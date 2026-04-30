<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Portability_Normalizer
{
    public const NORMALIZATION_VERSION = 1;

    /**
     * @var array<int, string>
     */
    private const VOLATILE_KEYS = [
        'created_at',
        'updated_at',
        'modified',
        'modified_at',
        'modified_gmt',
        'updated_by',
        'user_id',
        'userId',
        'timestamp',
        'time',
        'last_generated',
        'generated_at',
        'lastModified',
        '_lastEdited',
    ];

    /**
     * @param array<string, mixed> $domain_definition
     * @return array<string, mixed>
     */
    public static function normalize_live_domain(array $domain_definition)
    {
        $option_values = [];
        foreach ((array) ($domain_definition['option_names'] ?? []) as $option_name) {
            $option_name = sanitize_key((string) $option_name);
            if ($option_name === '') {
                continue;
            }
            $option_values[$option_name] = get_option($option_name, null);
        }

        return self::normalize_domain($domain_definition, $option_values, 'live');
    }

    /**
     * @param array<string, mixed> $domain_definition
     * @param array<string, mixed> $option_values
     * @return array<string, mixed>
     */
    public static function normalize_package_domain(array $domain_definition, array $option_values)
    {
        return self::normalize_domain($domain_definition, $option_values, 'package');
    }

    /**
     * @param array<string, mixed> $domain_definition
     * @param array<string, mixed> $option_values
     * @param string $source
     * @return array<string, mixed>
     */
    public static function normalize_domain(array $domain_definition, array $option_values, $source = 'live')
    {
        $domain_key = sanitize_key((string) ($domain_definition['domain_key'] ?? ''));
        $primary_option = sanitize_key((string) ($domain_definition['primary_option'] ?? ''));
        $mode = sanitize_key((string) ($domain_definition['mode'] ?? 'singleton'));
        $label = sanitize_text_field((string) ($domain_definition['label'] ?? $domain_key));

        $normalized = [
            'domain_key' => $domain_key,
            'label' => $label,
            'mode' => $mode,
            'source' => sanitize_key((string) $source),
            'normalization_version' => self::NORMALIZATION_VERSION,
            'source_option_names' => (array) ($domain_definition['option_names'] ?? []),
            'option_values' => $option_values,
            'primary_option' => $primary_option,
            'objects' => [],
            'metadata_rows' => [],
            'warnings' => [],
            'verification' => [],
            'transport' => [
                'shape' => 'singleton',
                'path' => [],
                'wrapper_shape' => 'root',
            ],
        ];

        $primary_raw = $primary_option !== '' && array_key_exists($primary_option, $option_values)
            ? $option_values[$primary_option]
            : null;
        $normalized['verification'] = DBVC_Bricks_Portability_Domain_Verifier::verify_domain_payload($domain_definition, $option_values, $source);
        $normalized['warnings'] = self::merge_warning_lists(
            $normalized['warnings'],
            (array) ($normalized['verification']['warnings'] ?? [])
        );

        if ($mode === 'collection') {
            $collection = self::extract_collection($primary_raw, $domain_key);
            $normalized['objects'] = $collection['objects'];
            $normalized['transport'] = $collection['transport'];
            $normalized['warnings'] = self::merge_warning_lists($normalized['warnings'], $collection['warnings']);
        } else {
            $root_normalized = self::normalize_value($primary_raw, $domain_key, $domain_key === 'breakpoints');
            $normalized['objects'][] = [
                'source_key' => $domain_key . '::root',
                'display_name' => $label,
                'object_id' => $primary_option !== '' ? $primary_option : '__root__',
                'match_keys' => [
                    'singleton' => '__root__',
                ],
                'fingerprint' => DBVC_Bricks_Portability_Utils::fingerprint($root_normalized),
                'references' => [
                    'css_variables' => DBVC_Bricks_Portability_Utils::extract_css_variable_tokens($root_normalized),
                    'class_names' => [],
                    'category_values' => [],
                    'category_option_name' => '',
                ],
                'warnings' => self::merge_warning_lists([], (array) ($normalized['verification']['warnings'] ?? [])),
                'raw' => $primary_raw,
                'normalized' => $root_normalized,
                'source_index' => 0,
                'transport' => [
                    'shape' => 'singleton',
                    'path' => [],
                ],
            ];
        }

        foreach ((array) ($domain_definition['related_options'] ?? []) as $option_name) {
            $option_name = sanitize_key((string) $option_name);
            $exists = array_key_exists($option_name, $option_values);
            $raw = $exists ? $option_values[$option_name] : null;
            if (! $exists && $raw === null) {
                continue;
            }

            $normalized_payload = self::normalize_value($raw, $domain_key, false);
            $normalized['metadata_rows'][] = [
                'row_id' => $domain_key . '::meta::' . $option_name,
                'row_type' => 'meta',
                'display_name' => 'Related Metadata: ' . $option_name,
                'object_id' => $option_name,
                'option_name' => $option_name,
                'fingerprint' => DBVC_Bricks_Portability_Utils::fingerprint($normalized_payload),
                'raw' => $raw,
                'normalized' => $normalized_payload,
            ];
        }

        $normalized['domain_fingerprint'] = DBVC_Bricks_Portability_Utils::fingerprint([
            'objects' => array_map(static function ($object) {
                return [
                    'source_key' => (string) ($object['source_key'] ?? ''),
                    'fingerprint' => (string) ($object['fingerprint'] ?? ''),
                ];
            }, $normalized['objects']),
            'metadata_rows' => array_map(static function ($row) {
                return [
                    'row_id' => (string) ($row['row_id'] ?? ''),
                    'fingerprint' => (string) ($row['fingerprint'] ?? ''),
                ];
            }, $normalized['metadata_rows']),
        ]);

        return $normalized;
    }

    /**
     * @param mixed $raw
     * @param string $domain_key
     * @return array<int, string>
     */
    public static function extract_category_reference_values($raw, $domain_key)
    {
        $domain_key = sanitize_key((string) $domain_key);
        if (! in_array($domain_key, ['global_classes', 'global_variables'], true)) {
            return [];
        }

        $references = [];
        self::walk_for_category_references($raw, $references);
        $references = array_values(array_unique(array_filter($references)));
        sort($references, SORT_STRING);
        return $references;
    }

    /**
     * @param mixed $raw
     * @return array<int, string>
     */
    public static function extract_category_lookup_values($raw)
    {
        $values = [];
        self::walk_for_category_lookup_values($raw, $values);
        $values = array_values(array_unique(array_filter($values)));
        sort($values, SORT_STRING);
        return $values;
    }

    /**
     * @param mixed $raw
     * @param string $domain_key
     * @return array<string, mixed>
     */
    private static function extract_collection($raw, $domain_key)
    {
        $transport = [
            'shape' => 'singleton',
            'path' => [],
            'wrapper_shape' => 'root',
        ];
        $warnings = [];
        $objects = [];

        if (! is_array($raw)) {
            if ($raw !== null && $raw !== '') {
                $warnings[] = 'Primary domain option is not an array; using singleton fallback.';
                $objects[] = self::build_collection_object($raw, $domain_key, 0, '', $transport);
            }

            return [
                'objects' => $objects,
                'transport' => $transport,
                'warnings' => $warnings,
            ];
        }

        $candidate_keys = self::get_collection_keys($domain_key);
        foreach ($candidate_keys as $candidate_key) {
            if (! array_key_exists($candidate_key, $raw) || ! is_array($raw[$candidate_key])) {
                continue;
            }

            $container = $raw[$candidate_key];
            $transport = [
                'shape' => DBVC_Bricks_Portability_Utils::is_assoc($container) ? 'map' : 'list',
                'path' => [$candidate_key],
                'wrapper_shape' => 'object',
            ];
            $objects = self::hydrate_collection_objects($container, $domain_key, $transport);
            return [
                'objects' => $objects,
                'transport' => $transport,
                'warnings' => $warnings,
            ];
        }

        if (! DBVC_Bricks_Portability_Utils::is_assoc($raw)) {
            $transport = [
                'shape' => 'list',
                'path' => [],
                'wrapper_shape' => 'root',
            ];

            return [
                'objects' => self::hydrate_collection_objects($raw, $domain_key, $transport),
                'transport' => $transport,
                'warnings' => $warnings,
            ];
        }

        if (self::looks_like_map_of_objects($raw)) {
            $transport = [
                'shape' => 'map',
                'path' => [],
                'wrapper_shape' => 'root',
            ];

            return [
                'objects' => self::hydrate_collection_objects($raw, $domain_key, $transport),
                'transport' => $transport,
                'warnings' => $warnings,
            ];
        }

        $warnings[] = 'Collection shape could not be confidently derived; using singleton fallback.';
        $transport = [
            'shape' => 'singleton',
            'path' => [],
            'wrapper_shape' => 'root',
        ];
        $objects[] = self::build_collection_object($raw, $domain_key, 0, '', $transport);

        return [
            'objects' => $objects,
            'transport' => $transport,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<int|string, mixed> $container
     * @param string $domain_key
     * @param array<string, mixed> $transport
     * @return array<int, array<string, mixed>>
     */
    private static function hydrate_collection_objects(array $container, $domain_key, array $transport)
    {
        $objects = [];
        $index = 0;
        foreach ($container as $key => $item) {
            $map_key = is_int($key) ? '' : sanitize_text_field((string) $key);
            $objects[] = self::build_collection_object($item, $domain_key, $index, $map_key, $transport);
            $index++;
        }

        return $objects;
    }

    /**
     * @param mixed $raw
     * @param string $domain_key
     * @param int $index
     * @param string $map_key
     * @param array<string, mixed> $transport
     * @return array<string, mixed>
     */
    private static function build_collection_object($raw, $domain_key, $index, $map_key, array $transport)
    {
        $normalized = self::normalize_value($raw, $domain_key, false);
        $object_id = self::pick_object_id($raw, $domain_key, $map_key, $index);
        $display_name = self::pick_display_name($raw, $domain_key, $object_id, $map_key, $index);
        $class_refs = self::extract_class_references($raw);
        $category_refs = self::extract_category_reference_values($raw, $domain_key);

        $match_keys = [
            'id' => self::pick_first_value($raw, self::get_id_candidates($domain_key)),
            'name' => self::pick_first_value($raw, self::get_name_candidates($domain_key)),
            'slug' => self::pick_first_value($raw, self::get_slug_candidates($domain_key)),
            'token' => self::pick_first_value($raw, self::get_token_candidates($domain_key)),
            'selector' => self::pick_first_value($raw, self::get_selector_candidates($domain_key)),
            'map_key' => $map_key,
        ];

        if ($display_name === '') {
            $display_name = $object_id !== '' ? $object_id : ($map_key !== '' ? $map_key : 'Object ' . ((int) $index + 1));
        }

        $source_key = $domain_key . ':' . ($map_key !== '' ? $map_key : ($object_id !== '' ? $object_id : sanitize_title($display_name)));
        $warnings = [];
        if ($object_id === '' && trim((string) $match_keys['name']) === '' && $map_key === '') {
            $warnings[] = 'Object has no stable id or name and may require manual review.';
        }

        return [
            'source_key' => $source_key,
            'display_name' => $display_name,
            'object_id' => $object_id,
            'match_keys' => array_filter($match_keys, static function ($value) {
                return $value !== null && $value !== '';
            }),
            'fingerprint' => DBVC_Bricks_Portability_Utils::fingerprint($normalized),
            'references' => [
                'css_variables' => DBVC_Bricks_Portability_Utils::extract_css_variable_tokens($raw),
                'class_names' => $class_refs,
                'category_values' => $category_refs,
                'category_option_name' => self::get_category_option_name($domain_key),
            ],
            'warnings' => $warnings,
            'raw' => $raw,
            'normalized' => $normalized,
            'source_index' => (int) $index,
            'map_key' => $map_key,
            'transport' => $transport,
        ];
    }

    /**
     * @param mixed $value
     * @param string $domain_key
     * @param bool $preserve_numeric_order
     * @return mixed
     */
    public static function normalize_value($value, $domain_key, $preserve_numeric_order = false)
    {
        unset($domain_key);

        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                $string_key = is_int($key) ? (string) $key : (string) $key;
                if (in_array($string_key, self::VOLATILE_KEYS, true)) {
                    continue;
                }
                $clean[$key] = self::normalize_value($item, '', $preserve_numeric_order);
            }

            return DBVC_Bricks_Portability_Utils::deep_sort($clean, $preserve_numeric_order);
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }

    /**
     * @param string $domain_key
     * @return array<int, string>
     */
    private static function get_collection_keys($domain_key)
    {
        $map = [
            'color_palette' => ['palettes', 'colors', 'items', 'palette'],
            'global_classes' => ['classes', 'items', 'globalClasses'],
            'global_variables' => ['variables', 'items', 'globalVariables'],
            'pseudo_classes' => ['pseudoClasses', 'items', 'classes'],
            'theme_styles' => ['styles', 'items', 'themeStyles'],
            'components' => ['components', 'items', 'globalElements'],
        ];

        return $map[$domain_key] ?? ['items'];
    }

    /**
     * @param array<int|string, mixed> $value
     * @return bool
     */
    private static function looks_like_map_of_objects(array $value)
    {
        if (! DBVC_Bricks_Portability_Utils::is_assoc($value)) {
            return false;
        }

        $total = count($value);
        if ($total === 0) {
            return false;
        }

        $array_values = 0;
        foreach ($value as $item) {
            if (is_array($item)) {
                $array_values++;
            }
        }

        return $array_values >= max(1, (int) floor($total / 2));
    }

    /**
     * @param mixed $raw
     * @param string $domain_key
     * @param string $map_key
     * @param int $index
     * @return string
     */
    private static function pick_object_id($raw, $domain_key, $map_key, $index)
    {
        $value = self::pick_first_value($raw, self::get_id_candidates($domain_key));
        if ($value !== '') {
            return $value;
        }
        if ($map_key !== '') {
            return $map_key;
        }
        return 'index-' . (int) $index;
    }

    /**
     * @param mixed $raw
     * @param string $domain_key
     * @param string $object_id
     * @param string $map_key
     * @param int $index
     * @return string
     */
    private static function pick_display_name($raw, $domain_key, $object_id, $map_key, $index)
    {
        $value = self::pick_first_value($raw, self::get_name_candidates($domain_key));
        if ($value !== '') {
            return $value;
        }
        if ($map_key !== '') {
            return $map_key;
        }
        if ($object_id !== '') {
            return $object_id;
        }
        return 'Object ' . ((int) $index + 1);
    }

    /**
     * @param mixed $raw
     * @param array<int, string> $candidates
     * @return string
     */
    private static function pick_first_value($raw, array $candidates)
    {
        if (! is_array($raw)) {
            return is_scalar($raw) ? DBVC_Bricks_Portability_Utils::normalize_string($raw) : '';
        }

        foreach ($candidates as $candidate) {
            $value = self::read_candidate_value($raw, $candidate);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return DBVC_Bricks_Portability_Utils::normalize_string($value);
            }
        }

        return '';
    }

    /**
     * @param string $domain_key
     * @return array<int, string>
     */
    private static function get_id_candidates($domain_key)
    {
        $domain_key = sanitize_key((string) $domain_key);
        if ($domain_key === 'pseudo_classes') {
            return ['selector', 'id', '_id', 'uuid', 'uid', 'key', 'slug', 'token'];
        }
        if ($domain_key === 'components') {
            return ['id', '_id', 'uuid', 'uid', 'key', 'slug', 'token', 'elements.0.id', 'elements.0.name'];
        }
        return ['id', '_id', 'uuid', 'uid', 'key', 'slug', 'token'];
    }

    /**
     * @param string $domain_key
     * @return array<int, string>
     */
    private static function get_name_candidates($domain_key)
    {
        $domain_key = sanitize_key((string) $domain_key);
        if ($domain_key === 'pseudo_classes') {
            return ['selector', 'name', 'label', 'title', 'slug', 'token'];
        }
        if ($domain_key === 'components') {
            return ['label', 'name', 'title', 'elements.0.label', 'elements.0.name', 'settings.label', 'slug', 'token'];
        }
        return ['name', 'label', 'title', 'slug', 'token'];
    }

    /**
     * @param string $domain_key
     * @return array<int, string>
     */
    private static function get_slug_candidates($domain_key)
    {
        $domain_key = sanitize_key((string) $domain_key);
        if ($domain_key === 'components') {
            return ['slug', 'handle', 'token', 'id', 'uid', 'uuid', 'elements.0.name'];
        }
        return ['slug', 'handle', 'token'];
    }

    /**
     * @param string $domain_key
     * @return array<int, string>
     */
    private static function get_token_candidates($domain_key)
    {
        $domain_key = sanitize_key((string) $domain_key);
        if ($domain_key === 'global_variables') {
            return ['token', 'name', 'slug'];
        }
        if ($domain_key === 'components') {
            return ['token', 'slug', 'name', 'elements.0.name', 'elements.0.label'];
        }
        return ['token', 'name', 'slug'];
    }

    /**
     * @param string $domain_key
     * @return array<int, string>
     */
    private static function get_selector_candidates($domain_key)
    {
        $domain_key = sanitize_key((string) $domain_key);
        if ($domain_key === 'pseudo_classes') {
            return ['selector', 'name', 'label'];
        }
        return ['selector', 'name'];
    }

    /**
     * @param array<string, mixed> $raw
     * @param string $candidate
     * @return mixed
     */
    private static function read_candidate_value(array $raw, $candidate)
    {
        $candidate = (string) $candidate;
        if ($candidate === '') {
            return null;
        }

        if (strpos($candidate, '.') === false) {
            return array_key_exists($candidate, $raw) ? $raw[$candidate] : null;
        }

        return self::read_path($raw, $candidate);
    }

    /**
     * @param array<string, mixed> $payload
     * @param string $path
     * @return mixed
     */
    private static function read_path(array $payload, $path)
    {
        $value = $payload;
        foreach (explode('.', (string) $path) as $part) {
            if (! is_array($value)) {
                return null;
            }
            if (ctype_digit($part)) {
                $index = (int) $part;
                if (! array_key_exists($index, $value)) {
                    return null;
                }
                $value = $value[$index];
                continue;
            }
            if (! array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }

    /**
     * @param mixed $raw
     * @return array<int, string>
     */
    private static function extract_class_references($raw)
    {
        $references = [];
        self::walk_for_class_references($raw, $references);
        $references = array_values(array_unique(array_filter($references)));
        sort($references, SORT_STRING);
        return $references;
    }

    /**
     * @param mixed $value
     * @param array<int, string> $references
     * @param string $key
     * @return void
     */
    private static function walk_for_class_references($value, array &$references, $key = '')
    {
        $key = sanitize_key((string) $key);
        if (is_array($value)) {
            foreach ($value as $child_key => $child_value) {
                self::walk_for_class_references($child_value, $references, (string) $child_key);
            }
            return;
        }

        if (! is_scalar($value)) {
            return;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return;
        }

        if (in_array($key, ['class', 'classes', 'classname', 'class_name', 'globalclasses'], true)) {
            foreach (preg_split('/\s+|,/', $string) as $token) {
                $token = sanitize_text_field((string) $token);
                if ($token !== '') {
                    $references[] = $token;
                }
            }
        }
    }

    /**
     * @param array<int, string> $warnings
     * @param array<int, string> $next
     * @return array<int, string>
     */
    private static function merge_warning_lists(array $warnings, array $next)
    {
        return array_values(array_unique(array_map('sanitize_text_field', array_merge($warnings, $next))));
    }

    /**
     * @param string $domain_key
     * @return string
     */
    private static function get_category_option_name($domain_key)
    {
        $domain_key = sanitize_key((string) $domain_key);
        if ($domain_key === 'global_classes') {
            return 'bricks_global_classes_categories';
        }
        if ($domain_key === 'global_variables') {
            return 'bricks_global_variables_categories';
        }
        return '';
    }

    /**
     * @param mixed $value
     * @param array<int, string> $references
     * @param string $key
     * @return void
     */
    private static function walk_for_category_references($value, array &$references, $key = '')
    {
        $key = sanitize_key((string) $key);
        if (is_array($value)) {
            foreach ($value as $child_key => $child_value) {
                if (is_string($child_key) && strpos(strtolower($child_key), 'categor') !== false) {
                    self::collect_category_reference_values($child_value, $references);
                }
                self::walk_for_category_references($child_value, $references, (string) $child_key);
            }
            return;
        }

        if ($key !== '' && strpos($key, 'categor') !== false && is_scalar($value)) {
            $clean = DBVC_Bricks_Portability_Utils::normalize_string($value);
            if ($clean !== '') {
                $references[] = $clean;
            }
        }
    }

    /**
     * @param mixed $value
     * @param array<int, string> $references
     * @return void
     */
    private static function collect_category_reference_values($value, array &$references)
    {
        if (is_array($value)) {
            if (DBVC_Bricks_Portability_Utils::is_assoc($value)) {
                foreach (['id', 'name', 'slug', 'label', 'title', 'value', 'category', 'key'] as $candidate) {
                    if (! array_key_exists($candidate, $value)) {
                        continue;
                    }
                    $clean = DBVC_Bricks_Portability_Utils::normalize_string($value[$candidate]);
                    if ($clean !== '') {
                        $references[] = $clean;
                    }
                }
            } else {
                foreach ($value as $item) {
                    self::collect_category_reference_values($item, $references);
                }
            }
            return;
        }

        if (! is_scalar($value)) {
            return;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return;
        }

        foreach (preg_split('/\s*,\s*/', $string) as $token) {
            $token = DBVC_Bricks_Portability_Utils::normalize_string($token);
            if ($token !== '') {
                $references[] = $token;
            }
        }
    }

    /**
     * @param mixed $value
     * @param array<int, string> $values
     * @return void
     */
    private static function walk_for_category_lookup_values($value, array &$values)
    {
        if (is_scalar($value)) {
            $clean = DBVC_Bricks_Portability_Utils::normalize_string($value);
            if ($clean !== '') {
                $values[] = $clean;
            }
            return;
        }

        if (is_array($value)) {
            if (DBVC_Bricks_Portability_Utils::is_assoc($value)) {
                $matched = false;
                foreach (['id', 'name', 'slug', 'label', 'title', 'key'] as $candidate) {
                    if (! array_key_exists($candidate, $value)) {
                        continue;
                    }
                    $clean = DBVC_Bricks_Portability_Utils::normalize_string($value[$candidate]);
                    if ($clean === '') {
                        continue;
                    }
                    $values[] = $clean;
                    $matched = true;
                }

                if ($matched) {
                    return;
                }
            }

            foreach ($value as $child) {
                self::walk_for_category_lookup_values($child, $values);
            }
        }
    }
}
