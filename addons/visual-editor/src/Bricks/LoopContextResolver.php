<?php

namespace Dbvc\VisualEditor\Bricks;

final class LoopContextResolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve()
    {
        if (! class_exists('\\Bricks\\Query') || ! method_exists('\\Bricks\\Query', 'is_any_looping')) {
            return [
                'active' => false,
            ];
        }

        $query_id = \Bricks\Query::is_any_looping();
        if (! $query_id) {
            return [
                'active' => false,
            ];
        }

        $context = $this->buildLoopContext((string) $query_id);

        if (empty($context)) {
            return [
                'active' => false,
            ];
        }

        $parent_loop_id = method_exists('\\Bricks\\Query', 'get_parent_loop_id')
            ? \Bricks\Query::get_parent_loop_id()
            : false;
        $parent_context = $parent_loop_id ? $this->buildLoopContext((string) $parent_loop_id) : [];
        $effective_owner = ! empty($context['owner_entity'])
            ? $context['owner_entity']
            : (isset($parent_context['owner_entity']) && is_array($parent_context['owner_entity']) ? $parent_context['owner_entity'] : []);

        $context['effective_owner_entity'] = $effective_owner;
        $context['parent'] = $parent_context;
        $context['signature'] = $this->buildSignature($context, $parent_context);

        return $context;
    }

    /**
     * @param string $query_id
     * @return array<string, mixed>
     */
    private function buildLoopContext($query_id)
    {
        $query_id = sanitize_text_field((string) $query_id);
        if ($query_id === '') {
            return [];
        }

        $query_object_type = method_exists('\\Bricks\\Query', 'get_query_object_type')
            ? sanitize_key((string) \Bricks\Query::get_query_object_type($query_id))
            : '';
        $loop_object_type = method_exists('\\Bricks\\Query', 'get_loop_object_type')
            ? sanitize_key((string) \Bricks\Query::get_loop_object_type($query_id))
            : '';
        $loop_object_id = method_exists('\\Bricks\\Query', 'get_loop_object_id')
            ? absint(\Bricks\Query::get_loop_object_id($query_id))
            : 0;
        $loop_index = method_exists('\\Bricks\\Query', 'get_loop_index')
            ? $this->normalizeScalar(\Bricks\Query::get_loop_index($query_id))
            : '';
        $query_element_id = method_exists('\\Bricks\\Query', 'get_query_element_id')
            ? sanitize_text_field((string) \Bricks\Query::get_query_element_id($query_id))
            : '';
        $loop_object = method_exists('\\Bricks\\Query', 'get_loop_object')
            ? \Bricks\Query::get_loop_object($query_id)
            : null;
        $owner_entity = $this->mapLoopObjectToEntity($loop_object_type, $loop_object_id, $loop_object);

        return [
            'active' => true,
            'query_id' => $query_id,
            'query_element_id' => $query_element_id,
            'query_object_type' => $query_object_type,
            'loop_object_type' => $loop_object_type,
            'loop_object_id' => $loop_object_id,
            'loop_index' => $loop_index,
            'owner_entity' => $owner_entity,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return bool
     */
    public function supportsRelatedPostEditing(array $context)
    {
        $owner = isset($context['effective_owner_entity']) && is_array($context['effective_owner_entity'])
            ? $context['effective_owner_entity']
            : (isset($context['owner_entity']) && is_array($context['owner_entity']) ? $context['owner_entity'] : []);

        if (empty($context['active'])
            || ! isset($owner['type'], $owner['id'])
            || (string) $owner['type'] !== 'post'
            || absint($owner['id']) <= 0) {
            return false;
        }

        if (isset($context['query_object_type']) && in_array((string) $context['query_object_type'], ['relationship', 'post_object'], true)) {
            return true;
        }

        $parent = isset($context['parent']) && is_array($context['parent']) ? $context['parent'] : [];

        return isset($context['query_object_type'])
            && strpos((string) $context['query_object_type'], 'acf_') === 0
            && isset($parent['query_object_type'])
            && in_array((string) $parent['query_object_type'], ['relationship', 'post_object'], true);
    }

    /**
     * @param array<string, mixed> $context
     * @return bool
     */
    public function hasConcretePostOwner(array $context)
    {
        $owner = isset($context['effective_owner_entity']) && is_array($context['effective_owner_entity'])
            ? $context['effective_owner_entity']
            : (isset($context['owner_entity']) && is_array($context['owner_entity']) ? $context['owner_entity'] : []);

        return ! empty($context['active'])
            && isset($owner['type'], $owner['id'])
            && (string) $owner['type'] === 'post'
            && absint($owner['id']) > 0;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function export(array $context)
    {
        if (empty($context['active'])) {
            return [];
        }

        return [
            'active' => true,
            'query_id' => isset($context['query_id']) ? sanitize_text_field((string) $context['query_id']) : '',
            'query_element_id' => isset($context['query_element_id']) ? sanitize_text_field((string) $context['query_element_id']) : '',
            'query_object_type' => isset($context['query_object_type']) ? sanitize_key((string) $context['query_object_type']) : '',
            'loop_object_type' => isset($context['loop_object_type']) ? sanitize_key((string) $context['loop_object_type']) : '',
            'loop_object_id' => isset($context['loop_object_id']) ? absint($context['loop_object_id']) : 0,
            'loop_index' => isset($context['loop_index']) ? $this->normalizeScalar($context['loop_index']) : '',
            'signature' => isset($context['signature']) ? sanitize_text_field((string) $context['signature']) : '',
            'has_concrete_post_owner' => $this->hasConcretePostOwner($context),
            'supports_related_post_editing' => $this->supportsRelatedPostEditing($context),
            'parent_query_object_type' => isset($context['parent']['query_object_type']) ? sanitize_key((string) $context['parent']['query_object_type']) : '',
            'parent_loop_object_type' => isset($context['parent']['loop_object_type']) ? sanitize_key((string) $context['parent']['loop_object_type']) : '',
            'parent_loop_object_id' => isset($context['parent']['loop_object_id']) ? absint($context['parent']['loop_object_id']) : 0,
            'parent_loop_index' => isset($context['parent']['loop_index']) ? $this->normalizeScalar($context['parent']['loop_index']) : '',
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return string
     */
    public function getSignature(array $context)
    {
        return isset($context['signature']) ? sanitize_text_field((string) $context['signature']) : '';
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $parent_context
     * @return string
     */
    private function buildSignature(array $context, array $parent_context = [])
    {
        if (method_exists('\\Bricks\\Query', 'get_looping_unique_identifier')) {
            $unique = \Bricks\Query::get_looping_unique_identifier('interaction');

            if (is_scalar($unique) && (string) $unique !== '') {
                return sanitize_text_field((string) $unique);
            }
        }

        return implode(
            '|',
            [
                sanitize_text_field((string) ($context['query_id'] ?? '')),
                sanitize_text_field((string) ($context['query_element_id'] ?? '')),
                sanitize_key((string) ($context['query_object_type'] ?? '')),
                sanitize_key((string) ($context['loop_object_type'] ?? '')),
                (string) absint($context['loop_object_id'] ?? 0),
                sanitize_text_field((string) ($context['loop_index'] ?? '')),
                sanitize_text_field((string) ($parent_context['query_element_id'] ?? '')),
                sanitize_key((string) ($parent_context['query_object_type'] ?? '')),
                sanitize_key((string) ($parent_context['loop_object_type'] ?? '')),
                (string) absint($parent_context['loop_object_id'] ?? 0),
                sanitize_text_field((string) ($parent_context['loop_index'] ?? '')),
            ]
        );
    }

    /**
     * @param string $loop_object_type
     * @param int    $loop_object_id
     * @param mixed  $loop_object
     * @return array<string, mixed>
     */
    private function mapLoopObjectToEntity($loop_object_type, $loop_object_id, $loop_object)
    {
        if ($loop_object_type === 'post' && $loop_object_id > 0) {
            return [
                'type' => 'post',
                'id' => $loop_object_id,
                'subtype' => (string) get_post_type($loop_object_id),
                'acf_object_id' => $loop_object_id,
            ];
        }

        if ($loop_object_type === 'term' && $loop_object instanceof \WP_Term && $loop_object_id > 0) {
            $taxonomy = sanitize_key((string) $loop_object->taxonomy);

            return [
                'type' => 'term',
                'id' => $loop_object_id,
                'subtype' => $taxonomy,
                'taxonomy' => $taxonomy,
                'acf_object_id' => $taxonomy !== '' ? $taxonomy . '_' . $loop_object_id : '',
            ];
        }

        if ($loop_object_type === 'user' && $loop_object_id > 0) {
            return [
                'type' => 'user',
                'id' => $loop_object_id,
                'subtype' => 'user',
                'acf_object_id' => 'user_' . $loop_object_id,
            ];
        }

        return [];
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function normalizeScalar($value)
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value) || $value === null) {
            return sanitize_text_field((string) $value);
        }

        return '';
    }
}
