<?php

namespace Dbvc\VisualEditor\MediaManager;

use WP_Post;
use WP_Term;

final class AcfMediaFieldCatalog
{
    /**
     * @var EligibilityPolicy
     */
    private $eligibility;

    /**
     * @var array<int, mixed>|null
     */
    private $field_groups = null;

    /**
     * @var array<string, array<int, mixed>|null>
     */
    private $fields_by_group = [];

    public function __construct(EligibilityPolicy $eligibility)
    {
        $this->eligibility = $eligibility;
    }

    /**
     * @param WP_Post|int $post
     * @return array<string, mixed>
     */
    public function forPost($post)
    {
        $post = $post instanceof WP_Post
            ? $post
            : (is_numeric($post) && absint($post) > 0 ? get_post(absint($post)) : null);
        $assessment = $this->eligibility->assessPost($post);

        if (! ($post instanceof WP_Post)) {
            return $this->emptyResult($assessment);
        }

        return $this->buildCatalog(
            $assessment,
            [
                'post_id' => absint($post->ID),
                'post_type' => sanitize_key((string) $post->post_type),
            ],
            (string) absint($post->ID)
        );
    }

    /**
     * @param WP_Term|int $term
     * @return array<string, mixed>
     */
    public function forTerm($term)
    {
        $term = $term instanceof WP_Term
            ? $term
            : (is_numeric($term) && absint($term) > 0 ? get_term(absint($term)) : null);
        $assessment = $this->eligibility->assessTerm($term);

        if (is_wp_error($term) || ! ($term instanceof WP_Term)) {
            return $this->emptyResult($assessment);
        }

        $taxonomy = sanitize_key((string) $term->taxonomy);

        return $this->buildCatalog(
            $assessment,
            [
                'term_id' => absint($term->term_id),
                'taxonomy' => $taxonomy,
            ],
            $taxonomy . '_' . absint($term->term_id)
        );
    }

    /**
     * Build a stable fingerprint from the active ACF definition and location
     * inputs that can materially change scan applicability or stored labels.
     * Owner-specific visibility is still evaluated separately for every entity.
     *
     * @return string
     */
    public function getDefinitionFingerprint($refresh = false)
    {
        if (! function_exists('acf_get_field_groups') || ! function_exists('acf_get_fields')) {
            return hash('sha256', 'acf_unavailable');
        }

        if ($refresh) {
            $this->field_groups = null;
            $this->fields_by_group = [];
        }

        $definitions = [];
        foreach ($this->getFieldGroups() as $group) {
            if (! is_array($group)) {
                continue;
            }

            $group_key = $this->fieldIdentifier($group['key'] ?? '');
            if ($group_key === '') {
                continue;
            }

            $fields = $this->getFieldsForGroup($group_key);
            $definitions[] = [
                'key' => $group_key,
                'title' => sanitize_text_field((string) ($group['title'] ?? $group['label'] ?? '')),
                'active' => ! array_key_exists('active', $group) || ! empty($group['active']),
                'location' => $this->normalizeFingerprintValue($group['location'] ?? []),
                'fields' => $this->normalizeFingerprintFields(is_array($fields) ? $fields : []),
            ];
        }

        usort($definitions, static function ($left, $right) {
            return strcmp((string) ($left['key'] ?? ''), (string) ($right['key'] ?? ''));
        });

        $json = wp_json_encode($definitions);

        return hash('sha256', is_string($json) ? $json : 'acf_definition_encoding_failed');
    }

    /**
     * @param array<string, mixed> $assessment
     * @param array<string, mixed> $screen
     * @param string               $acf_object_id
     * @return array<string, mixed>
     */
    private function buildCatalog(array $assessment, array $screen, $acf_object_id)
    {
        if (empty($assessment['eligible'])) {
            return $this->emptyResult($assessment, $acf_object_id);
        }

        if (! function_exists('acf_get_field_groups')
            || ! function_exists('acf_get_fields')
            || ! function_exists('acf_get_field_group_visibility')) {
            $result = $this->emptyResult($assessment, $acf_object_id);
            $result['reason'] = 'acf_unavailable';

            return $result;
        }

        if (function_exists('acf_get_location_screen')) {
            $normalized_screen = acf_get_location_screen($screen);
            if (is_array($normalized_screen)) {
                $screen = $normalized_screen;
            }
        }

        $result = $this->emptyResult($assessment, $acf_object_id);
        $result['available'] = true;
        $result['reason'] = 'cataloged';
        $result['screen'] = $this->normalizeScreen($screen);
        $groups = $this->getFieldGroups();

        if (! is_array($groups)) {
            return $result;
        }

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            if (array_key_exists('active', $group) && empty($group['active'])) {
                $result['counts']['inactive_groups']++;
                continue;
            }

            try {
                $visible = (bool) acf_get_field_group_visibility($group, $screen);
            } catch (\Throwable $throwable) {
                unset($throwable);
                $result['counts']['visibility_errors']++;
                continue;
            }

            if (! $visible) {
                $result['counts']['inapplicable_groups']++;
                continue;
            }

            $group_key = $this->fieldIdentifier($group['key'] ?? '');
            if ($group_key === '') {
                $result['counts']['invalid_groups']++;
                continue;
            }

            $fields = $this->getFieldsForGroup($group_key);
            if (! is_array($fields)) {
                $result['counts']['invalid_groups']++;
                continue;
            }

            $result['counts']['applicable_groups']++;
            $group_context = [
                'key' => $group_key,
                'label' => sanitize_text_field((string) ($group['title'] ?? $group['label'] ?? $group_key)),
            ];

            $this->walkFields($fields, $group_context, [], false, $result);
        }

        return $result;
    }

    /**
     * @param array<int, mixed>    $fields
     * @param array<string, mixed> $group
     * @param array<int, array<string, string>> $ancestors
     * @param bool                 $ancestor_conditional
     * @param array<string, mixed> $result
     * @return void
     */
    private function walkFields(array $fields, array $group, array $ancestors, $ancestor_conditional, array &$result)
    {
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $type = sanitize_key((string) ($field['type'] ?? ''));
            $conditional = (bool) $ancestor_conditional || ! empty($field['conditional_logic']);

            if ($type === 'clone') {
                $result['counts']['clone_fields']++;
            }

            if (in_array($type, ['image', 'gallery'], true)) {
                $this->classifyMediaField($field, $group, $ancestors, $conditional, $result);
                continue;
            }

            $segment = $this->fieldSegment($field);
            $next_ancestors = $ancestors;
            if ($type !== '') {
                $next_ancestors[] = $segment;
            }

            if ($type === 'flexible_content') {
                $layouts = isset($field['layouts']) && is_array($field['layouts']) ? $field['layouts'] : [];

                foreach ($layouts as $layout) {
                    if (! is_array($layout) || empty($layout['sub_fields']) || ! is_array($layout['sub_fields'])) {
                        continue;
                    }

                    $this->walkFields($layout['sub_fields'], $group, $next_ancestors, $conditional, $result);
                }

                continue;
            }

            if (! empty($field['sub_fields']) && is_array($field['sub_fields'])) {
                $this->walkFields($field['sub_fields'], $group, $next_ancestors, $conditional, $result);
            }
        }
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $group
     * @param array<int, array<string, string>> $ancestors
     * @param bool                 $conditional
     * @param array<string, mixed> $result
     * @return void
     */
    private function classifyMediaField(array $field, array $group, array $ancestors, $conditional, array &$result)
    {
        $ancestor_types = array_values(array_filter(array_map(static function ($ancestor) {
            return sanitize_key((string) ($ancestor['type'] ?? ''));
        }, $ancestors)));

        if ($conditional) {
            $result['counts']['conditional_fields']++;
            return;
        }

        if (in_array('repeater', $ancestor_types, true)) {
            $result['counts']['repeater_fields']++;
            return;
        }

        if (in_array('flexible_content', $ancestor_types, true)) {
            $result['counts']['flexible_content_fields']++;
            return;
        }

        $unsupported_types = array_values(array_diff($ancestor_types, ['group']));
        if (! empty($unsupported_types)) {
            $result['counts']['unsupported_nested_fields']++;
            return;
        }

        $field_segment = $this->fieldSegment($field);
        $path = array_merge($ancestors, [$field_segment]);
        $root = ! empty($ancestors) ? $ancestors[0] : $field_segment;
        $field_type = sanitize_key((string) ($field['type'] ?? ''));
        $path_kind = empty($ancestors) ? 'top_level' : 'group';

        $result['fields'][] = [
            'field_key' => $this->fieldIdentifier($field['key'] ?? ''),
            'field_name' => $this->fieldIdentifier($field['name'] ?? ''),
            'field_label' => sanitize_text_field((string) ($field['label'] ?? $field['name'] ?? '')),
            'field_type' => $field_type,
            'group_key' => $this->fieldIdentifier($group['key'] ?? ''),
            'group_label' => sanitize_text_field((string) ($group['label'] ?? '')),
            'path_kind' => $path_kind,
            'root_field_key' => $this->fieldIdentifier($root['key'] ?? ''),
            'root_field_name' => $this->fieldIdentifier($root['name'] ?? ''),
            'selector' => $this->fieldIdentifier(($root['key'] ?? '') !== '' ? $root['key'] : ($root['name'] ?? '')),
            'path' => $path,
            'group_path' => array_values(array_map(function ($ancestor) {
                return $this->fieldIdentifier($ancestor['name'] ?? '');
            }, $ancestors)),
        ];

        $result['counts']['supported_fields']++;
        $result['counts'][$field_type . '_fields']++;
        $result['counts'][$path_kind . '_fields']++;
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, string>
     */
    private function fieldSegment(array $field)
    {
        return [
            'key' => $this->fieldIdentifier($field['key'] ?? ''),
            'name' => $this->fieldIdentifier($field['name'] ?? ''),
            'label' => sanitize_text_field((string) ($field['label'] ?? $field['name'] ?? '')),
            'type' => sanitize_key((string) ($field['type'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $assessment
     * @param string               $acf_object_id
     * @return array<string, mixed>
     */
    private function emptyResult(array $assessment, $acf_object_id = '')
    {
        return [
            'available' => false,
            'eligible' => ! empty($assessment['eligible']),
            'reason' => sanitize_key((string) ($assessment['reason'] ?? 'ineligible')),
            'owner' => [
                'type' => sanitize_key((string) ($assessment['entity_type'] ?? '')),
                'id' => absint($assessment['entity_id'] ?? 0),
                'subtype' => sanitize_key((string) ($assessment['subtype'] ?? '')),
                'acf_object_id' => sanitize_text_field((string) $acf_object_id),
            ],
            'screen' => [],
            'fields' => [],
            'counts' => [
                'supported_fields' => 0,
                'image_fields' => 0,
                'gallery_fields' => 0,
                'top_level_fields' => 0,
                'group_fields' => 0,
                'conditional_fields' => 0,
                'repeater_fields' => 0,
                'flexible_content_fields' => 0,
                'unsupported_nested_fields' => 0,
                'clone_fields' => 0,
                'applicable_groups' => 0,
                'inapplicable_groups' => 0,
                'inactive_groups' => 0,
                'invalid_groups' => 0,
                'visibility_errors' => 0,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $screen
     * @return array<string, mixed>
     */
    private function normalizeScreen(array $screen)
    {
        $normalized = [];

        foreach (['post_id', 'post_type', 'post_status', 'page_template', 'page_type', 'taxonomy', 'term_id'] as $key) {
            if (! isset($screen[$key]) || is_array($screen[$key]) || is_object($screen[$key])) {
                continue;
            }

            $normalized[$key] = in_array($key, ['post_id', 'term_id'], true)
                ? absint($screen[$key])
                : sanitize_text_field((string) $screen[$key]);
        }

        return $normalized;
    }

    /**
     * @param array<int, mixed> $fields
     * @return array<int, array<string, mixed>>
     */
    private function normalizeFingerprintFields(array $fields)
    {
        $normalized = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $item = [
                'key' => $this->fieldIdentifier($field['key'] ?? ''),
                'name' => $this->fieldIdentifier($field['name'] ?? ''),
                'label' => sanitize_text_field((string) ($field['label'] ?? '')),
                'type' => sanitize_key((string) ($field['type'] ?? '')),
                'conditional' => ! empty($field['conditional_logic']),
            ];

            if (! empty($field['sub_fields']) && is_array($field['sub_fields'])) {
                $item['sub_fields'] = $this->normalizeFingerprintFields($field['sub_fields']);
            }

            if (! empty($field['layouts']) && is_array($field['layouts'])) {
                $item['layouts'] = [];
                foreach ($field['layouts'] as $layout) {
                    if (! is_array($layout)) {
                        continue;
                    }

                    $item['layouts'][] = [
                        'key' => $this->fieldIdentifier($layout['key'] ?? ''),
                        'name' => $this->fieldIdentifier($layout['name'] ?? ''),
                        'sub_fields' => $this->normalizeFingerprintFields(
                            isset($layout['sub_fields']) && is_array($layout['sub_fields'])
                                ? $layout['sub_fields']
                                : []
                        ),
                    ];
                }
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * Normalize the scalar/list structure used by ACF location rules without
     * preserving objects, callbacks, or runtime-only values.
     *
     * @param mixed $value
     * @return mixed
     */
    private function normalizeFingerprintValue($value)
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[is_int($key) ? $key : sanitize_key((string) $key)] = $this->normalizeFingerprintValue($item);
            }

            return $normalized;
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        return is_scalar($value) ? sanitize_text_field((string) $value) : '';
    }

    /**
     * Runtime group visibility remains owner-specific, but the active definition
     * list can be reused throughout one bounded scan request.
     *
     * @return array<int, mixed>
     */
    private function getFieldGroups()
    {
        if (is_array($this->field_groups)) {
            return $this->field_groups;
        }

        $groups = acf_get_field_groups();
        $this->field_groups = is_array($groups) ? $groups : [];

        return $this->field_groups;
    }

    /**
     * @param string $group_key
     * @return array<int, mixed>|null
     */
    private function getFieldsForGroup($group_key)
    {
        if (array_key_exists($group_key, $this->fields_by_group)) {
            return $this->fields_by_group[$group_key];
        }

        $fields = acf_get_fields($group_key);
        $this->fields_by_group[$group_key] = is_array($fields) ? $fields : null;

        return $this->fields_by_group[$group_key];
    }

    /**
     * Preserve ACF's exact mixed-case selector identity while removing characters
     * that cannot participate in field keys or names.
     *
     * @param mixed $value
     * @return string
     */
    private function fieldIdentifier($value)
    {
        return (string) preg_replace('/[^A-Za-z0-9_:-]/', '', trim((string) $value));
    }
}
