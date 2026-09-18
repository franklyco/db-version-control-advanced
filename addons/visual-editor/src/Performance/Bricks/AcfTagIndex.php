<?php

namespace Dbvc\VisualEditor\Performance\Bricks;

/**
 * R5.later-perf-a — sub-field-key → tag-name index for Bricks' ACF dynamic-data
 * provider.
 *
 * Bricks' `Provider_Acf::get_nested_parent_group_field_data()` answers "which
 * registered tag's field has sub-field KEY?" by scanning every registered tag
 * and each of its sub-fields, once per nested sub-field — O(tags × sub-fields)
 * per request (≈ 1.5 s on a 2 700-tag schema, E-159). This index answers the
 * same question in O(duplicate names) while preserving the scan's exact
 * semantics: the FIRST tag in array order whose CURRENT sub-fields carry the
 * key. Tag names can be overwritten by later duplicate-named fields, so every
 * hit is validated against the live tag before it counts.
 *
 * Pure PHP, no Bricks dependency — unit-tested against the reference scan.
 */
final class AcfTagIndex
{
    /**
     * @var array<string, array<string, true>> sub_field key => [tag name => true]
     */
    private $byKey = [];

    /**
     * @var array<string, int> tag name => first-insertion position (= array order of the tags map)
     */
    private $position = [];

    /**
     * @var int
     */
    private $next = 0;

    /**
     * Record a tag assignment (`$tags[$name] = $tag`). Safe to call for every
     * assignment including overwrites; a name keeps its original position.
     *
     * @param string               $name
     * @param array<string, mixed> $tag
     * @return void
     */
    public function indexTag($name, array $tag)
    {
        if (! isset($this->position[$name])) {
            $this->position[$name] = $this->next++;
        }

        foreach (self::subFieldKeys($tag) as $key) {
            $this->byKey[$key][$name] = true;
        }
    }

    /**
     * Name of the first tag (array order) whose current sub-fields carry $key,
     * or null — identical to referenceLookup() when every assignment has been
     * indexed.
     *
     * @param string                              $key
     * @param array<string, array<string, mixed>> $tags live tag map
     * @return string|null
     */
    public function lookup($key, array $tags)
    {
        $found = null;
        $best = PHP_INT_MAX;

        foreach ($this->byKey[$key] ?? [] as $name => $_) {
            if ($this->position[$name] < $best && isset($tags[$name]) && self::tagHasSubFieldKey($tags[$name], $key)) {
                $best = $this->position[$name];
                $found = $name;
            }
        }

        return $found;
    }

    /**
     * The stock Bricks scan, kept as the reference oracle for tests and the
     * runtime self-check.
     *
     * @param string                              $key
     * @param array<string, array<string, mixed>> $tags
     * @return string|null
     */
    public static function referenceLookup($key, array $tags)
    {
        foreach ($tags as $name => $tag) {
            if (self::tagHasSubFieldKey($tag, $key)) {
                return (string) $name;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $tag
     * @return array<int, string>
     */
    private static function subFieldKeys(array $tag)
    {
        $keys = [];

        foreach ($tag['field']['sub_fields'] ?? [] as $sub_field) {
            if (is_array($sub_field) && isset($sub_field['key']) && is_string($sub_field['key'])) {
                $keys[] = $sub_field['key'];
            }
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $tag
     * @param string               $key
     * @return bool
     */
    private static function tagHasSubFieldKey(array $tag, $key)
    {
        foreach ($tag['field']['sub_fields'] ?? [] as $sub_field) {
            if (is_array($sub_field) && ($sub_field['key'] ?? null) === $key) {
                return true;
            }
        }

        return false;
    }
}
