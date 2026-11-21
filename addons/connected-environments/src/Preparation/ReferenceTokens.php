<?php

namespace Dbvc\Connected\Preparation;

/**
 * Portable, identity-independent tokens for cross-object references inside a
 * Bricks component (M9 slice 6c).
 *
 * A Bricks component's element tree references other framework objects by
 * their site-local id: `elements[*].settings._cssGlobalClasses` lists global
 * class ids, `_cssGlobalClassesProps` keys by class id, and `cid` /
 * `parentComponent` name a nested component instance; a Template element
 * (`name: template`) names the Bricks template it renders by local POST id
 * (`settings.template`, older `settings.templateId`). Those ids differ on every
 * site, so a component projected with raw ids would never hash equal across the
 * fleet and could never converge by the release's `after_hash`.
 *
 * This helper rewrites each such reference to a token keyed by the referenced
 * object's PORTABLE identity — `dbvc-ref:class:<name>` / `dbvc-ref:component:<name>`
 * / `dbvc-ref:template:<vf_object_uid>` — exactly as M7 tokenized media to a
 * content hash. The projection then hashes
 * the same on source and target, so verify and the hub baseline need no
 * per-domain exception; at apply the connector detokenizes each token back to
 * its own local id. An unresolvable reference is left literal so the dependency
 * ledger can block it rather than silently writing a dangling id. Nothing here
 * reads or writes the database; the id⇄name maps are supplied by the caller.
 */
final class ReferenceTokens
{
    public const TOKEN_PREFIX = 'dbvc-ref:';
    public const KIND_CLASS = 'class';
    public const KIND_COMPONENT = 'component';
    public const KIND_TEMPLATE = 'template';

    /**
     * Rewrite every cross-object id reference in a component to a name token.
     *
     * @param array<string, mixed>  $component
     * @param array<string, string> $class_id_to_name     source global-class id => name
     * @param array<string, string> $component_id_to_name  source component id => name
     * @param array<string, string> $template_id_to_uid    source bricks_template post id => vf_object_uid
     * @return array<string, mixed>
     */
    public static function tokenize(array $component, array $class_id_to_name, array $component_id_to_name, array $template_id_to_uid = [])
    {
        return self::walk($component, static function ($kind, $id) use ($class_id_to_name, $component_id_to_name, $template_id_to_uid) {
            $map = $kind === self::KIND_CLASS ? $class_id_to_name : ($kind === self::KIND_TEMPLATE ? $template_id_to_uid : $component_id_to_name);
            $name = isset($map[$id]) && is_scalar($map[$id]) ? (string) $map[$id] : '';

            // Unresolvable id → leave it literal; the dependency ledger reports it.
            return $name === '' ? $id : self::token($kind, $name);
        });
    }

    /**
     * Resolve every reference token back to a site-local id.
     *
     * @param array<string, mixed>  $component
     * @param array<string, string> $class_name_to_id     target name => global-class id
     * @param array<string, string> $component_name_to_id  target name => component id
     * @param array<string, string> $template_uid_to_id    target vf_object_uid => bricks_template post id
     * @return array<string, mixed>
     */
    public static function detokenize(array $component, array $class_name_to_id, array $component_name_to_id, array $template_uid_to_id = [])
    {
        return self::walk($component, static function ($kind, $value) use ($class_name_to_id, $component_name_to_id, $template_uid_to_id) {
            $parsed = self::parse($value);
            if ($parsed === null || $parsed['kind'] !== $kind) {
                return $value; // not a token of this kind: leave as-is (already a local id, or a foreign token).
            }
            $map = $kind === self::KIND_CLASS ? $class_name_to_id : ($kind === self::KIND_TEMPLATE ? $template_uid_to_id : $component_name_to_id);

            // Unresolvable token → leave it literal so verify fails closed rather than writing a dangling id.
            return isset($map[$parsed['name']]) && is_scalar($map[$parsed['name']]) ? (string) $map[$parsed['name']] : $value;
        });
    }

    /**
     * Every reference token present in a component body.
     *
     * @param array<string, mixed> $component
     * @return array<int, array{kind: string, name: string, token: string}>
     */
    public static function tokens_in_body(array $component)
    {
        $found = [];
        self::walk($component, static function ($kind, $value) use (&$found) {
            $parsed = self::parse($value);
            if ($parsed !== null && $parsed['kind'] === $kind) {
                $found[$parsed['kind'] . ':' . $parsed['name']] = ['kind' => $parsed['kind'], 'name' => $parsed['name'], 'token' => $value];
            }

            return $value;
        });

        return array_values($found);
    }

    /**
     * @param string $kind
     * @param string $name
     * @return string
     */
    public static function token($kind, $name)
    {
        return self::TOKEN_PREFIX . $kind . ':' . rawurlencode((string) $name);
    }

    /**
     * @param mixed $value
     * @return array{kind: string, name: string}|null
     */
    public static function parse($value)
    {
        if (! is_string($value) || strpos($value, self::TOKEN_PREFIX) !== 0) {
            return null;
        }
        $rest = substr($value, strlen(self::TOKEN_PREFIX));
        $sep = strpos($rest, ':');
        if ($sep === false) {
            return null;
        }
        $kind = substr($rest, 0, $sep);
        if ($kind !== self::KIND_CLASS && $kind !== self::KIND_COMPONENT && $kind !== self::KIND_TEMPLATE) {
            return null;
        }

        return ['kind' => $kind, 'name' => rawurldecode(substr($rest, $sep + 1))];
    }

    /**
     * Walk a component tree, applying $map to each reference value in place:
     * every entry of a `_cssGlobalClasses` list and every KEY of a
     * `_cssGlobalClassesProps` map are class references; `cid` and
     * `parentComponent` scalars are component references; a Template element's
     * `settings.template` / `settings.templateId` is a template reference (only
     * under an element whose `name` is `template`, so other controls that happen
     * to carry a `template` key are never mistaken for one). An object's own
     * `id` is never touched. $map is called as ($kind, $value) and returns the
     * replacement value.
     *
     * @param array<string, mixed> $node
     * @param callable             $map
     * @return array<string, mixed>
     */
    private static function walk(array $node, callable $map)
    {
        $is_template_element = isset($node['name']) && $node['name'] === 'template' && isset($node['settings']) && is_array($node['settings']);
        $out = [];
        foreach ($node as $key => $value) {
            if ($is_template_element && $key === 'settings') {
                $settings = $value;
                foreach (['template', 'templateId'] as $ref_key) {
                    if (isset($settings[$ref_key]) && is_scalar($settings[$ref_key]) && (string) $settings[$ref_key] !== '') {
                        $mapped = $map(self::KIND_TEMPLATE, (string) $settings[$ref_key]);
                        // Unresolved: keep the original scalar (and its type) untouched.
                        $settings[$ref_key] = $mapped === (string) $settings[$ref_key] ? $settings[$ref_key] : $mapped;
                    }
                }
                $out[$key] = self::walk($settings, $map);
                continue;
            }
            if ($key === '_cssGlobalClasses' && is_array($value)) {
                $out[$key] = array_map(static function ($id) use ($map) {
                    return is_scalar($id) ? $map(self::KIND_CLASS, (string) $id) : $id;
                }, $value);
                continue;
            }
            if ($key === '_cssGlobalClassesProps' && is_array($value)) {
                $rekeyed = [];
                foreach ($value as $class_id => $props) {
                    $new_key = is_scalar($class_id) ? $map(self::KIND_CLASS, (string) $class_id) : $class_id;
                    $rekeyed[$new_key] = is_array($props) ? self::walk($props, $map) : $props;
                }
                $out[$key] = $rekeyed;
                continue;
            }
            if (($key === 'cid' || $key === 'parentComponent') && is_scalar($value)) {
                $out[$key] = $map(self::KIND_COMPONENT, (string) $value);
                continue;
            }
            $out[$key] = is_array($value) ? self::walk($value, $map) : $value;
        }

        return $out;
    }
}
