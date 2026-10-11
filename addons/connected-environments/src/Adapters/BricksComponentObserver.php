<?php

namespace Dbvc\Connected\Adapters;

use Dbvc\Connected\Preparation\ReferenceTokens;
use Dbvc\ConnectedProtocol\Canonicalizer;

/**
 * Read-only observer for the `bricks_components` option (M9 slice 6c).
 *
 * A component is a collection member keyed by its random `id`, like a global
 * class, but its element tree carries cross-object id references — global class
 * ids in `elements[*].settings._cssGlobalClasses` / `_cssGlobalClassesProps`,
 * and nested-component instance ids in `cid` / `parentComponent`. This observer
 * rewrites them to portable NAME tokens ({@see ReferenceTokens}) BEFORE hashing:
 * the projection hash is then identical across the fleet even though each site's
 * local ids differ, and the component converges by the release `after_hash`
 * with no per-domain verify exception.
 *
 * The component's own id and the component-internal property/group ids are left
 * untouched (they travel inside the blob and identify the member itself). The
 * component has no top-level name, so its portable display name is its root
 * element's label.
 */
final class BricksComponentObserver extends BricksOptionCollectionObserver
{
    use BricksReferenceMaps;

    /**
     * Rewrite cross-object references to portable name tokens, then project.
     *
     * @param array<string, mixed> $entry
     * @param array<int, string>   $volatile
     * @return array<string, mixed>
     */
    protected function project_member(array $entry, array $volatile)
    {
        $tokenized = ReferenceTokens::tokenize($entry, $this->bricks_class_id_to_name(), $this->bricks_component_id_to_name());

        return Canonicalizer::project($tokenized, $volatile);
    }

    /**
     * @param array<string, mixed> $entry
     * @return string The component's portable name (its root element's label).
     */
    protected function member_display_name(array $entry)
    {
        return self::component_record_name($entry);
    }

    /**
     * At apply, resolve every portable reference token back to THIS site's local
     * id (the inverse of the observe-time tokenization). The written body then
     * carries local class/component ids, yet re-projects to the release
     * `after_hash` (the projection re-tokenizes by name), so verify passes. A
     * token with no local match is left literal; the dependency ledger has
     * already fenced that case off at prepare, so it only arises on a race and
     * then fails verify closed.
     *
     * @param array<string, mixed> $after
     * @return array<string, mixed>
     */
    public function prepare_body_for_apply(array $after)
    {
        return ReferenceTokens::detokenize($after, $this->bricks_class_name_to_id(), $this->bricks_component_name_to_id());
    }

    /**
     * Back-compat alias for the component's portable name (used by the
     * dependency ledger and tests). See {@see BricksReferenceMaps::component_record_name}.
     *
     * @param array<string, mixed> $component
     * @return string
     */
    public static function component_name(array $component)
    {
        return self::component_record_name($component);
    }
}
