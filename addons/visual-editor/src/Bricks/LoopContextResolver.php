<?php

namespace Dbvc\VisualEditor\Bricks;

use Dbvc\VisualEditor\Performance\PerformanceProfiler;

final class LoopContextResolver
{
    /**
     * @var NativeAcfQueryResolver
     */
    private $native_acf_queries;

    /**
     * @var array<string, string>
     */
    private $element_query_object_types = [];

    /**
     * @var PerformanceProfiler|null
     */
    private $profiler;

    /**
     * @var array<string, array<string, mixed>>
     */
    private $resolved_context_cache = [];

    /**
     * @var array<int, string>
     */
    private $resolved_context_cache_order = [];

    public function __construct(?NativeAcfQueryResolver $native_acf_queries = null, ?PerformanceProfiler $profiler = null)
    {
        $this->native_acf_queries = $native_acf_queries instanceof NativeAcfQueryResolver
            ? $native_acf_queries
            : new NativeAcfQueryResolver();
        $this->profiler = $profiler;
    }

    /**
     * Preserve the original Bricks element query object type before Bricks normalizes
     * relationship/post_object loops to a concrete WP object loop at runtime.
     *
     * @param string $element_id
     * @param string $query_object_type
     * @return void
     */
    public function rememberElementQueryObjectType($element_id, $query_object_type)
    {
        $element_id = sanitize_text_field((string) $element_id);
        $query_object_type = sanitize_key((string) $query_object_type);

        if ($element_id === '' || strpos($query_object_type, 'acf_') !== 0) {
            return;
        }

        if (isset($this->element_query_object_types[$element_id]) && $this->element_query_object_types[$element_id] === $query_object_type) {
            return;
        }

        $this->element_query_object_types[$element_id] = $query_object_type;
        $this->clearResolvedContextCache();
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve()
    {
        if ($this->profiler instanceof PerformanceProfiler && $this->profiler->isEnabled()) {
            $started_at = $this->profiler->startTimer();
            $context = $this->resolveUnprofiled();
            $this->profiler->recordDuration('loop_context.resolve', $started_at, [
                'active' => empty($context['active']) ? 'no' : 'yes',
            ]);

            return $context;
        }

        return $this->resolveUnprofiled();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveUnprofiled()
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

        $query_id = sanitize_text_field((string) $query_id);
        $looping_query_ids = $this->getActiveLoopingQueryIds();
        $loop_snapshots = $this->buildLoopRuntimeSnapshots($looping_query_ids);
        $cache_key = $this->buildResolvedContextCacheKey($query_id, $looping_query_ids, $loop_snapshots);
        if ($cache_key !== '' && isset($this->resolved_context_cache[$cache_key]) && is_array($this->resolved_context_cache[$cache_key])) {
            return $this->resolved_context_cache[$cache_key];
        }

        $contexts = $this->buildDecoratedLoopContexts($looping_query_ids, $loop_snapshots);

        if (! isset($contexts[$query_id]) || empty($contexts[$query_id])) {
            $context = [
                'active' => false,
            ];
            $this->rememberResolvedContextCache($cache_key, $context);

            return $context;
        }

        $context = $contexts[$query_id];
        $parent_loop_id = $this->findParentLoopId($looping_query_ids, $query_id);
        $parent_context = $parent_loop_id && isset($contexts[$parent_loop_id]) ? $contexts[$parent_loop_id] : [];
        $context['parent'] = $parent_context;
        $context['parent_native_acf_query'] = isset($parent_context['native_acf_query']) && is_array($parent_context['native_acf_query'])
            ? $parent_context['native_acf_query']
            : [];
        $context['ancestors'] = $this->collectAncestorContexts($looping_query_ids, $contexts, $query_id);
        $context['native_acf_query_ancestry'] = $this->buildNativeAcfQueryAncestry($context);
        $context['signature'] = $this->buildSignature($context);
        $this->rememberResolvedContextCache($cache_key, $context);

        return $context;
    }

    /**
     * @return void
     */
    private function clearResolvedContextCache()
    {
        $this->resolved_context_cache = [];
        $this->resolved_context_cache_order = [];
    }

    /**
     * @param string                                      $query_id
     * @param array<int, string>                          $looping_query_ids
     * @param array<string, array<string, mixed>>          $loop_snapshots
     * @return string
     */
    private function buildResolvedContextCacheKey($query_id, array $looping_query_ids, array $loop_snapshots = [])
    {
        $query_id = sanitize_text_field((string) $query_id);
        if ($query_id === '' || empty($looping_query_ids)) {
            return '';
        }

        $parts = [
            'active',
            $query_id,
        ];

        if (method_exists('\\Bricks\\Query', 'get_looping_unique_identifier')) {
            $unique = \Bricks\Query::get_looping_unique_identifier('interaction');
            if (is_scalar($unique) && (string) $unique !== '') {
                $parts[] = 'unique:' . sanitize_text_field((string) $unique);
            }
        }

        foreach ($looping_query_ids as $looping_query_id) {
            $looping_query_id = sanitize_text_field((string) $looping_query_id);
            if ($looping_query_id === '') {
                continue;
            }

            $snapshot = isset($loop_snapshots[$looping_query_id]) && is_array($loop_snapshots[$looping_query_id])
                ? $loop_snapshots[$looping_query_id]
                : [];
            $loop_object_type = isset($snapshot['loop_object_type']) ? sanitize_key((string) $snapshot['loop_object_type']) : '';
            $loop_object_id = isset($snapshot['loop_object_id']) ? absint($snapshot['loop_object_id']) : 0;
            $loop_index = isset($snapshot['loop_index']) ? $this->normalizeScalar($snapshot['loop_index']) : '';
            $query_element_id = isset($snapshot['query_element_id']) ? sanitize_text_field((string) $snapshot['query_element_id']) : '';
            $runtime_query_object_type = isset($snapshot['runtime_query_object_type']) ? sanitize_key((string) $snapshot['runtime_query_object_type']) : '';
            $query_object_type = isset($snapshot['query_object_type'])
                ? sanitize_key((string) $snapshot['query_object_type'])
                : $this->resolveEffectiveQueryObjectType($query_element_id, $runtime_query_object_type);

            $parts[] = implode(':', [
                $looping_query_id,
                $loop_object_type,
                (string) $loop_object_id,
                $loop_index,
                $query_element_id,
                $runtime_query_object_type,
                $query_object_type,
            ]);
        }

        if (count($parts) <= 2) {
            return '';
        }

        return hash('sha256', implode('|', $parts));
    }

    /**
     * @param string               $cache_key
     * @param array<string, mixed> $context
     * @return void
     */
    private function rememberResolvedContextCache($cache_key, array $context)
    {
        $cache_key = sanitize_key((string) $cache_key);
        if ($cache_key === '') {
            return;
        }

        if (! isset($this->resolved_context_cache[$cache_key])) {
            $this->resolved_context_cache_order[] = $cache_key;
        }

        $this->resolved_context_cache[$cache_key] = $context;

        while (count($this->resolved_context_cache_order) > 512) {
            $oldest = array_shift($this->resolved_context_cache_order);
            if (is_string($oldest) && $oldest !== '') {
                unset($this->resolved_context_cache[$oldest]);
            }
        }
    }

    /**
     * @param string               $query_id
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function buildLoopContext($query_id, array $snapshot = [])
    {
        $query_id = sanitize_text_field((string) $query_id);
        if ($query_id === '') {
            return [];
        }

        if (empty($snapshot)) {
            $snapshot = $this->buildLoopRuntimeSnapshot($query_id);
        }

        $loop_object_type = isset($snapshot['loop_object_type']) ? sanitize_key((string) $snapshot['loop_object_type']) : '';
        $loop_object_id = isset($snapshot['loop_object_id']) ? absint($snapshot['loop_object_id']) : 0;
        $loop_index = isset($snapshot['loop_index']) ? $this->normalizeScalar($snapshot['loop_index']) : '';
        $query_element_id = isset($snapshot['query_element_id']) ? sanitize_text_field((string) $snapshot['query_element_id']) : '';
        $runtime_query_object_type = isset($snapshot['runtime_query_object_type']) ? sanitize_key((string) $snapshot['runtime_query_object_type']) : '';
        $query_object_type = isset($snapshot['query_object_type'])
            ? sanitize_key((string) $snapshot['query_object_type'])
            : $this->resolveEffectiveQueryObjectType($query_element_id, $runtime_query_object_type);
        $loop_object = array_key_exists('loop_object', $snapshot)
            ? $snapshot['loop_object']
            : (method_exists('\\Bricks\\Query', 'get_loop_object') ? \Bricks\Query::get_loop_object($query_id) : null);
        $owner_entity = $this->mapLoopObjectToEntity($loop_object_type, $loop_object_id, $loop_object, $query_object_type);

        return [
            'active' => true,
            'query_id' => $query_id,
            'query_element_id' => $query_element_id,
            'query_object_type' => $query_object_type,
            'runtime_query_object_type' => $runtime_query_object_type,
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
        if (! $this->supportsLoopOwnedEditing($context)) {
            return false;
        }

        $owner = isset($context['effective_owner_entity']) && is_array($context['effective_owner_entity'])
            ? $context['effective_owner_entity']
            : (isset($context['owner_entity']) && is_array($context['owner_entity']) ? $context['owner_entity'] : []);

        return isset($owner['type'], $owner['id'])
            && (string) $owner['type'] === 'post'
            && absint($owner['id']) > 0;
    }

    /**
     * @param array<string, mixed> $context
     * @return bool
     */
    public function hasConcreteOwner(array $context)
    {
        $owner = isset($context['effective_owner_entity']) && is_array($context['effective_owner_entity'])
            ? $context['effective_owner_entity']
            : (isset($context['owner_entity']) && is_array($context['owner_entity']) ? $context['owner_entity'] : []);

        return ! empty($context['active'])
            && isset($owner['type'], $owner['id'])
            && in_array((string) $owner['type'], ['post', 'term', 'user'], true)
            && absint($owner['id']) > 0;
    }

    /**
     * @param array<string, mixed> $context
     * @return bool
     */
    public function supportsLoopOwnedEditing(array $context)
    {
        if (! $this->hasConcreteOwner($context)) {
            return false;
        }

        return true;
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
            'runtime_query_object_type' => isset($context['runtime_query_object_type']) ? sanitize_key((string) $context['runtime_query_object_type']) : '',
            'loop_object_type' => isset($context['loop_object_type']) ? sanitize_key((string) $context['loop_object_type']) : '',
            'loop_object_id' => isset($context['loop_object_id']) ? absint($context['loop_object_id']) : 0,
            'loop_index' => isset($context['loop_index']) ? $this->normalizeScalar($context['loop_index']) : '',
            'has_concrete_owner' => $this->hasConcreteOwner($context),
            'signature' => isset($context['signature']) ? sanitize_text_field((string) $context['signature']) : '',
            'has_concrete_post_owner' => $this->hasConcretePostOwner($context),
            'supports_loop_owned_editing' => $this->supportsLoopOwnedEditing($context),
            'supports_related_post_editing' => $this->supportsRelatedPostEditing($context),
            'effective_owner_type' => isset($context['effective_owner_entity']['type']) ? sanitize_key((string) $context['effective_owner_entity']['type']) : '',
            'effective_owner_id' => isset($context['effective_owner_entity']['id']) ? absint($context['effective_owner_entity']['id']) : 0,
            'effective_owner_subtype' => isset($context['effective_owner_entity']['subtype']) ? sanitize_key((string) $context['effective_owner_entity']['subtype']) : '',
            'native_acf_query' => $this->exportNativeAcfQuery(isset($context['native_acf_query']) && is_array($context['native_acf_query']) ? $context['native_acf_query'] : []),
            'parent_native_acf_query' => $this->exportNativeAcfQuery(isset($context['parent_native_acf_query']) && is_array($context['parent_native_acf_query']) ? $context['parent_native_acf_query'] : []),
            'native_acf_query_ancestry' => $this->exportNativeAcfQueryAncestry($context),
            'parent_query_object_type' => isset($context['parent']['query_object_type']) ? sanitize_key((string) $context['parent']['query_object_type']) : '',
            'parent_runtime_query_object_type' => isset($context['parent']['runtime_query_object_type']) ? sanitize_key((string) $context['parent']['runtime_query_object_type']) : '',
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
     * @return string
     */
    private function buildSignature(array $context)
    {
        $parts = [];

        if (method_exists('\\Bricks\\Query', 'get_looping_unique_identifier')) {
            $unique = \Bricks\Query::get_looping_unique_identifier('interaction');

            if (is_scalar($unique) && (string) $unique !== '') {
                $parts[] = sanitize_text_field((string) $unique);
            }
        }

        $signature_contexts = [$context];
        if (! empty($context['ancestors']) && is_array($context['ancestors'])) {
            foreach ($context['ancestors'] as $ancestor) {
                if (is_array($ancestor) && ! empty($ancestor)) {
                    $signature_contexts[] = $ancestor;
                }
            }
        }

        foreach ($signature_contexts as $signature_context) {
            $effective_owner = isset($signature_context['effective_owner_entity']) && is_array($signature_context['effective_owner_entity'])
                ? $signature_context['effective_owner_entity']
                : [];
            $parts = array_merge(
                $parts,
                [
                    sanitize_text_field((string) ($signature_context['query_id'] ?? '')),
                    sanitize_text_field((string) ($signature_context['query_element_id'] ?? '')),
                    sanitize_key((string) ($signature_context['query_object_type'] ?? '')),
                    sanitize_key((string) ($signature_context['native_acf_query']['selector'] ?? '')),
                    sanitize_key((string) ($signature_context['native_acf_query']['kind'] ?? '')),
                    sanitize_key((string) ($signature_context['loop_object_type'] ?? '')),
                    (string) absint($signature_context['loop_object_id'] ?? 0),
                    sanitize_text_field((string) ($signature_context['loop_index'] ?? '')),
                    sanitize_key((string) ($effective_owner['type'] ?? '')),
                    (string) absint($effective_owner['id'] ?? 0),
                ]
            );
        }

        return implode('|', $parts);
    }

    /**
     * @param string $query_element_id
     * @param string $runtime_query_object_type
     * @return string
     */
    private function resolveEffectiveQueryObjectType($query_element_id, $runtime_query_object_type)
    {
        $query_element_id = sanitize_text_field((string) $query_element_id);
        $runtime_query_object_type = sanitize_key((string) $runtime_query_object_type);

        if ($query_element_id === '' || strpos($runtime_query_object_type, 'acf_') === 0) {
            return $runtime_query_object_type;
        }

        if (isset($this->element_query_object_types[$query_element_id])) {
            return sanitize_key((string) $this->element_query_object_types[$query_element_id]);
        }

        $base_element_id = preg_replace('/-[A-Za-z0-9]+$/', '', $query_element_id);
        $base_element_id = is_string($base_element_id) ? sanitize_text_field($base_element_id) : '';

        if ($base_element_id !== '' && isset($this->element_query_object_types[$base_element_id])) {
            return sanitize_key((string) $this->element_query_object_types[$base_element_id]);
        }

        return $runtime_query_object_type;
    }

    /**
     * @return array<int, string>
     */
    private function getActiveLoopingQueryIds()
    {
        global $bricks_loop_query;

        if (! is_array($bricks_loop_query) || empty($bricks_loop_query)) {
            return [];
        }

        $query_ids = array_reverse(array_keys($bricks_loop_query));
        $looping_query_ids = [];

        foreach ($query_ids as $query_id) {
            if (! isset($bricks_loop_query[$query_id]) || ! is_object($bricks_loop_query[$query_id])) {
                continue;
            }

            if (empty($bricks_loop_query[$query_id]->is_looping)) {
                continue;
            }

            $looping_query_ids[] = sanitize_text_field((string) $query_id);
        }

        return array_values(array_filter($looping_query_ids));
    }

    /**
     * @param array<int, string> $looping_query_ids
     * @return array<string, array<string, mixed>>
     */
    private function buildLoopRuntimeSnapshots(array $looping_query_ids)
    {
        $snapshots = [];
        foreach ($looping_query_ids as $query_id) {
            $query_id = sanitize_text_field((string) $query_id);
            if ($query_id === '') {
                continue;
            }

            $snapshots[$query_id] = $this->buildLoopRuntimeSnapshot($query_id);
        }

        return $snapshots;
    }

    /**
     * @param string $query_id
     * @param bool   $include_loop_object
     * @return array<string, mixed>
     */
    private function buildLoopRuntimeSnapshot($query_id, $include_loop_object = false)
    {
        $query_id = sanitize_text_field((string) $query_id);
        if ($query_id === '') {
            return [];
        }

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
        $runtime_query_object_type = method_exists('\\Bricks\\Query', 'get_query_object_type')
            ? sanitize_key((string) \Bricks\Query::get_query_object_type($query_id))
            : '';
        $query_object_type = $this->resolveEffectiveQueryObjectType($query_element_id, $runtime_query_object_type);

        $snapshot = [
            'loop_object_type' => $loop_object_type,
            'loop_object_id' => $loop_object_id,
            'loop_index' => $loop_index,
            'query_element_id' => $query_element_id,
            'runtime_query_object_type' => $runtime_query_object_type,
            'query_object_type' => $query_object_type,
        ];

        if ($include_loop_object) {
            $snapshot['loop_object'] = method_exists('\\Bricks\\Query', 'get_loop_object')
                ? \Bricks\Query::get_loop_object($query_id)
                : null;
        }

        return $snapshot;
    }

    /**
     * @param array<int, string>                 $looping_query_ids
     * @param array<string, array<string, mixed>> $loop_snapshots
     * @return array<string, array<string, mixed>>
     */
    private function buildDecoratedLoopContexts(array $looping_query_ids, array $loop_snapshots = [])
    {
        $contexts = [];
        $inherited_owner = [];

        foreach (array_reverse($looping_query_ids) as $query_id) {
            $query_id = sanitize_text_field((string) $query_id);
            $snapshot = isset($loop_snapshots[$query_id]) && is_array($loop_snapshots[$query_id])
                ? $loop_snapshots[$query_id]
                : [];
            $context = $this->buildLoopContext($query_id, $snapshot);
            if (empty($context)) {
                continue;
            }

            $effective_owner = ! empty($context['owner_entity'])
                ? $context['owner_entity']
                : $inherited_owner;
            $context['effective_owner_entity'] = $effective_owner;
            $context['native_acf_query'] = $this->native_acf_queries->resolve(
                isset($context['query_object_type']) ? (string) $context['query_object_type'] : '',
                is_array($effective_owner) ? $effective_owner : []
            );
            $contexts[$query_id] = $context;

            if (! empty($effective_owner)) {
                $inherited_owner = $effective_owner;
            }
        }

        return $contexts;
    }

    /**
     * @param array<int, string>                  $looping_query_ids
     * @param array<string, array<string, mixed>> $contexts
     * @param string                              $query_id
     * @return array<int, array<string, mixed>>
     */
    private function collectAncestorContexts(array $looping_query_ids, array $contexts, $query_id)
    {
        $position = array_search((string) $query_id, $looping_query_ids, true);
        if ($position === false) {
            return [];
        }

        $ancestors = [];
        foreach (array_slice($looping_query_ids, $position + 1) as $ancestor_query_id) {
            if (! isset($contexts[$ancestor_query_id]) || empty($contexts[$ancestor_query_id])) {
                continue;
            }

            $ancestors[] = $contexts[$ancestor_query_id];
        }

        return $ancestors;
    }

    /**
     * @param array<int, string> $looping_query_ids
     * @param string             $query_id
     * @return string|false
     */
    private function findParentLoopId(array $looping_query_ids, $query_id)
    {
        $position = array_search((string) $query_id, $looping_query_ids, true);
        if ($position === false) {
            return false;
        }

        return isset($looping_query_ids[$position + 1]) ? (string) $looping_query_ids[$position + 1] : false;
    }

    /**
     * @param string $loop_object_type
     * @param int    $loop_object_id
     * @param mixed  $loop_object
     * @return array<string, mixed>
     */
    private function mapLoopObjectToEntity($loop_object_type, $loop_object_id, $loop_object, $query_object_type = '')
    {
        $loop_object_type = sanitize_key((string) $loop_object_type);
        $query_object_type = sanitize_key((string) $query_object_type);
        $native_acf_query = $this->native_acf_queries->resolve($query_object_type, []);
        $is_native_repeater_like = ! empty($native_acf_query['isRepeaterLike']);
        $supports_native_concrete_owner = ! empty($native_acf_query['supportsConcreteOwner']);

        if (is_array($loop_object)) {
            $loop_object_id = $loop_object_id > 0
                ? $loop_object_id
                : absint($loop_object['ID'] ?? $loop_object['id'] ?? $loop_object['post_id'] ?? $loop_object['term_id'] ?? $loop_object['user_id'] ?? 0);
        }

        if ($loop_object instanceof \WP_Post) {
            $loop_object_id = $loop_object_id > 0 ? $loop_object_id : absint($loop_object->ID);
        }

        if ($loop_object instanceof \WP_User) {
            $loop_object_id = $loop_object_id > 0 ? $loop_object_id : absint($loop_object->ID);
        }

        if ($loop_object instanceof \WP_Term) {
            $loop_object_id = $loop_object_id > 0 ? $loop_object_id : absint($loop_object->term_id);
        }

        if (
            $loop_object_id > 0
            && is_array($loop_object)
            && (isset($loop_object['term_id']) || isset($loop_object['taxonomy']))
        ) {
            $taxonomy = sanitize_key((string) ($loop_object['taxonomy'] ?? $loop_object_type));

            if ($taxonomy !== '' && taxonomy_exists($taxonomy)) {
                return [
                    'type' => 'term',
                    'id' => $loop_object_id,
                    'subtype' => $taxonomy,
                    'taxonomy' => $taxonomy,
                    'acf_object_id' => $taxonomy . '_' . $loop_object_id,
                ];
            }
        }

        if (
            $loop_object_id > 0
            && is_array($loop_object)
            && (isset($loop_object['user_id']) || isset($loop_object['user_login']) || $loop_object_type === 'user')
        ) {
            return [
                'type' => 'user',
                'id' => $loop_object_id,
                'subtype' => 'user',
                'acf_object_id' => 'user_' . $loop_object_id,
            ];
        }

        if (
            $loop_object_id > 0
            && (
                $loop_object instanceof \WP_Post
                || (is_array($loop_object) && (isset($loop_object['post_type']) || isset($loop_object['post_status']) || isset($loop_object['post_title'])))
                || $loop_object_type === 'post'
                || ($loop_object_type !== '' && post_type_exists($loop_object_type))
            )
        ) {
            return [
                'type' => 'post',
                'id' => $loop_object_id,
                'subtype' => (string) get_post_type($loop_object_id),
                'acf_object_id' => $loop_object_id,
            ];
        }

        if (
            $loop_object_id > 0
            && $supports_native_concrete_owner
            && ! $is_native_repeater_like
            && get_post_type($loop_object_id)
        ) {
            return [
                'type' => 'post',
                'id' => $loop_object_id,
                'subtype' => (string) get_post_type($loop_object_id),
                'acf_object_id' => $loop_object_id,
            ];
        }

        if (
            $loop_object_id > 0
            && (
                $loop_object instanceof \WP_Term
                || $loop_object_type === 'term'
                || ($loop_object_type !== '' && taxonomy_exists($loop_object_type))
            )
        ) {
            $taxonomy = $loop_object instanceof \WP_Term
                ? sanitize_key((string) $loop_object->taxonomy)
                : $loop_object_type;

            return [
                'type' => 'term',
                'id' => $loop_object_id,
                'subtype' => $taxonomy,
                'taxonomy' => $taxonomy,
                'acf_object_id' => $taxonomy !== '' ? $taxonomy . '_' . $loop_object_id : '',
            ];
        }

        if ($loop_object_id > 0 && ($loop_object instanceof \WP_User || $loop_object_type === 'user')) {
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

    /**
     * @param array<string, mixed> $native_query
     * @return array<string, mixed>
     */
    private function exportNativeAcfQuery(array $native_query)
    {
        if (empty($native_query['active'])) {
            return [];
        }

        return [
            'active' => true,
            'source' => isset($native_query['source']) ? sanitize_key((string) $native_query['source']) : '',
            'objectType' => isset($native_query['objectType']) ? sanitize_key((string) $native_query['objectType']) : '',
            'selector' => isset($native_query['selector']) ? sanitize_key((string) $native_query['selector']) : '',
            'fieldName' => isset($native_query['fieldName']) ? sanitize_key((string) $native_query['fieldName']) : '',
            'fieldKey' => isset($native_query['fieldKey']) ? sanitize_key((string) $native_query['fieldKey']) : '',
            'fieldType' => isset($native_query['fieldType']) ? sanitize_key((string) $native_query['fieldType']) : '',
            'kind' => isset($native_query['kind']) ? sanitize_key((string) $native_query['kind']) : '',
            'path' => isset($native_query['path']) && is_array($native_query['path'])
                ? array_values(array_filter(array_map('sanitize_key', $native_query['path'])))
                : [],
            'supportsConcreteOwner' => ! empty($native_query['supportsConcreteOwner']),
            'isRepeaterLike' => ! empty($native_query['isRepeaterLike']),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    private function buildNativeAcfQueryAncestry(array $context)
    {
        $ancestors = isset($context['ancestors']) && is_array($context['ancestors']) ? $context['ancestors'] : [];
        if (empty($ancestors)) {
            return [];
        }

        $lineage = [];

        foreach (array_reverse($ancestors) as $ancestor) {
            if (! is_array($ancestor) || empty($ancestor)) {
                continue;
            }

            $native_query = isset($ancestor['native_acf_query']) && is_array($ancestor['native_acf_query'])
                ? $ancestor['native_acf_query']
                : [];
            if (empty($native_query['active'])) {
                continue;
            }

            $exported = $this->exportNativeAcfQuery($native_query);
            if (empty($exported)) {
                continue;
            }

            $exported['queryId'] = isset($ancestor['query_id']) ? sanitize_text_field((string) $ancestor['query_id']) : '';
            $exported['queryElementId'] = isset($ancestor['query_element_id']) ? sanitize_text_field((string) $ancestor['query_element_id']) : '';
            $exported['loopIndex'] = isset($ancestor['loop_index']) ? $this->normalizeScalar($ancestor['loop_index']) : '';
            $lineage[] = $exported;
        }

        return $lineage;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    private function exportNativeAcfQueryAncestry(array $context)
    {
        $lineage = isset($context['native_acf_query_ancestry']) && is_array($context['native_acf_query_ancestry'])
            ? $context['native_acf_query_ancestry']
            : $this->buildNativeAcfQueryAncestry($context);

        return array_values(
            array_filter(
                array_map(
                    function ($item) {
                        if (! is_array($item) || empty($item['active'])) {
                            return null;
                        }

                        $exported = $this->exportNativeAcfQuery($item);
                        if (empty($exported)) {
                            return null;
                        }

                        $exported['queryId'] = isset($item['queryId']) ? sanitize_text_field((string) $item['queryId']) : '';
                        $exported['queryElementId'] = isset($item['queryElementId']) ? sanitize_text_field((string) $item['queryElementId']) : '';
                        $exported['loopIndex'] = isset($item['loopIndex']) ? $this->normalizeScalar($item['loopIndex']) : '';

                        return $exported;
                    },
                    $lineage
                )
            )
        );
    }
}
