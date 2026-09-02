<?php

namespace Dbvc\VisualEditor\Registry\Providers;

use Dbvc\VisualEditor\Permissions\CapabilityManager;
use Dbvc\VisualEditor\Registry\ControlProvider;
use Dbvc\VisualEditor\Registry\ControlRecord;
use Dbvc\VisualEditor\Registry\EditableDescriptor;

/**
 * R3-B — Shared Globals compatibility provider.
 *
 * Adapts the existing `SharedGlobalFieldsController` field-enumeration path
 * onto the R3-A {@see \Dbvc\VisualEditor\Registry\ControlRegistry} as a
 * discovery-only surface. Each configured ACF options field name resolves to
 * one {@see \Dbvc\VisualEditor\Registry\ControlRecord} the Brand Control
 * Center list can render without minting an authoritative descriptor. The
 * existing Shared Globals toolbar popover keeps working exactly as it does
 * today — this provider is parallel, not a replacement.
 *
 * The provider carries no write authority. Descriptor minting and the actual
 * save path continue to route through
 * `SharedGlobalFieldsController::buildDescriptor` and the shared
 * `MutationService` pipeline (R3-C wires the open-time descriptor factory).
 *
 * Two callable seams keep this class testable without loading ACF:
 * - `$namesResolver` returns the configured Shared Globals field-name list
 *   (production: `\DBVC_Visual_Editor_Addon::get_shared_global_field_names`).
 * - `$fieldObjectResolver` returns the raw ACF field-object array for one
 *   name (production: `get_field_object($name, 'option', false, true)`), or
 *   `null`/`false` when ACF is unavailable or the name does not resolve.
 */
final class SharedGlobalsControlProvider implements ControlProvider
{
    public const PROVIDER_ID = 'shared_globals';

    /**
     * @var CapabilityManager
     */
    private $capabilities;

    /**
     * @var callable():array<int, string>
     */
    private $namesResolver;

    /**
     * @var callable(string):(array<string, mixed>|null|false)
     */
    private $fieldObjectResolver;

    /**
     * @var SharedGlobalsDescriptorFactory
     */
    private $descriptorFactory;

    /**
     * R4-A — resolves the raw stored value of one options-page ACF field
     * (relationship / post_object) so {@see buildValueSummary} can compute
     * the drawer's right-side chip without calling `get_field` directly (test
     * seam). Production wires an ACF-backed closure in
     * {@see \Dbvc\VisualEditor\Bootstrap\Addon}. May return array, scalar,
     * object, null, or false.
     *
     * @var callable(string):mixed
     */
    private $optionValueResolver;

    /**
     * @param CapabilityManager                                       $capabilities
     * @param callable():array<int, string>                           $namesResolver
     * @param callable(string):(array<string, mixed>|null|false)      $fieldObjectResolver
     * @param callable(string):mixed|null                             $optionValueResolver R4-A;
     *          Optional — defaults to a resolver that returns `null` so the
     *          registry ships the record without a summary chip until
     *          production wires an ACF-backed closure. Kept optional to
     *          preserve backwards-compatibility with R3-B call sites and tests.
     */
    public function __construct(
        CapabilityManager $capabilities,
        callable $namesResolver,
        callable $fieldObjectResolver,
        ?callable $optionValueResolver = null
    ) {
        $this->capabilities = $capabilities;
        $this->namesResolver = $namesResolver;
        $this->fieldObjectResolver = $fieldObjectResolver;
        $this->optionValueResolver = $optionValueResolver ?? static function ($fieldName) {
            return null;
        };
        $this->descriptorFactory = new SharedGlobalsDescriptorFactory();
    }

    /**
     * @return string
     */
    public function getProviderId()
    {
        return self::PROVIDER_ID;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getControls()
    {
        $names = call_user_func($this->namesResolver);
        if (! is_array($names) || empty($names)) {
            return [];
        }

        $capabilities = $this->capabilities;
        $records = [];
        $seen = [];

        foreach ($names as $configured_name) {
            $configured_name = sanitize_key((string) $configured_name);
            if ($configured_name === '' || isset($seen[$configured_name])) {
                continue;
            }

            $field = call_user_func($this->fieldObjectResolver, $configured_name);
            if (! is_array($field)) {
                continue;
            }

            $field_name = isset($field['name']) ? sanitize_key((string) $field['name']) : '';
            if ($field_name === '' || $field_name !== $configured_name) {
                continue;
            }

            $field_type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
            if (! in_array($field_type, ['relationship', 'post_object'], true)) {
                continue;
            }

            $field_key = isset($field['key']) ? sanitize_key((string) $field['key']) : '';
            $label = isset($field['label']) && is_scalar($field['label'])
                ? sanitize_text_field((string) $field['label'])
                : $field_name;
            $group_title = $this->resolveFieldGroupTitle($field);

            $seen[$configured_name] = true;

            // R4-A: description sourced from (in order):
            //   1) `dbvc_visual_editor_control_center_description` filter — the
            //      Vertical bridge hooks this to inject
            //      `vf_field_context_get_entry_primary_purpose()`. Filter
            //      receives the default + a small context bag so hooks can
            //      differentiate by provider / field / family.
            //   2) ACF's own `instructions` on the field object (fail-safe
            //      when no filter runs — surfaces the sitewide field-editor
            //      instructions on the drawer's muted second line).
            //   3) Empty — the drawer collapses the description slot when
            //      the value is empty (mockup DESIGN-DECISIONS §4).
            $default_description = isset($field['instructions']) && is_scalar($field['instructions'])
                ? sanitize_text_field((string) $field['instructions'])
                : '';
            $description = (string) apply_filters(
                'dbvc_visual_editor_control_center_description',
                $default_description,
                [
                    'providerId' => self::PROVIDER_ID,
                    'fieldName' => $field_name,
                    'fieldKey' => $field_key,
                    'fieldType' => $field_type,
                    'label' => $label,
                ]
            );

            // R4-A sortKey — `shared_{fieldName}` puts Shared Globals ahead
            // of other providers (e.g. `vertical_*`) under the R3-A registry's
            // stable `sortKey` ascending sort, and keeps per-field ordering
            // stable across restarts.
            $sort_key = sanitize_key('shared_' . $field_name);

            $records[] = [
                'id' => $field_name,
                'label' => $label,
                'description' => $description,
                'category' => 'globals',
                'group' => $group_title,
                'ownerType' => 'option',
                'ownerSubtype' => 'acf_options',
                'fieldFamily' => $field_type,
                'status' => 'available',
                'sortKey' => $sort_key,
                'source' => [
                    'field_name' => $field_name,
                    'field_key' => $field_key,
                ],
                'meta' => [
                    'badge' => __('Shared Global', 'dbvc'),
                ],
                'visibleTo' => static function () use ($capabilities) {
                    $probe = new EditableDescriptor(
                        've_shared_global_capability_probe',
                        'editable',
                        'shared_entity',
                        [
                            'type' => 'option',
                            'id' => 0,
                            'subtype' => 'acf_options',
                            'acf_object_id' => 'option',
                        ],
                        [],
                        [],
                        [],
                        []
                    );

                    return $capabilities->canEditDescriptor($probe);
                },
            ];
        }

        return $records;
    }

    /**
     * R3-C-1 — mint the authoritative Shared Globals descriptor at open time.
     *
     * Re-resolves the ACF field via the constructor's `$fieldObjectResolver`
     * seam (so stale records fail closed cleanly), re-validates that the type
     * is still `relationship`/`post_object` (a maintainer may have converted
     * the field between list and open), then delegates to the shared
     * {@see SharedGlobalsDescriptorFactory} so the descriptor is byte-identical
     * to what the existing Shared Globals popover route mints for the same
     * field. Returns null on any structural miss — the caller
     * ({@see \Dbvc\VisualEditor\Rest\Controllers\ControlCenterOpenController})
     * translates that into a fail-closed 404.
     *
     * @param ControlRecord        $record
     * @param string               $sessionId
     * @param array<string, mixed> $pageContext
     * @return EditableDescriptor|null
     */
    public function buildDescriptor(ControlRecord $record, $sessionId, array $pageContext)
    {
        $field_name = isset($record->source['field_name']) ? sanitize_key((string) $record->source['field_name']) : '';
        if ($field_name === '') {
            return null;
        }

        $field = call_user_func($this->fieldObjectResolver, $field_name);
        if (! is_array($field)) {
            return null;
        }

        $resolved_name = isset($field['name']) ? sanitize_key((string) $field['name']) : '';
        if ($resolved_name === '' || $resolved_name !== $field_name) {
            return null;
        }

        $field_type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
        if (! in_array($field_type, ['relationship', 'post_object'], true)) {
            return null;
        }

        return $this->descriptorFactory->build((string) $sessionId, $pageContext, $field);
    }

    /**
     * R4-A — mint a per-family value summary for the drawer's right-side
     * chip. Currently supports `relationship` + `post_object` (the two
     * families the R3-B provider emits as `status="available"`) and shapes
     * the summary as:
     *
     *   {
     *       family:      "relationship" | "post_object",
     *       count:       int,           // total related items on the option
     *       firstTitles: string[],      // up to 3, sanitize_text_field
     *       hasMore:     bool,          // count > firstTitles.length
     *   }
     *
     * Fails closed to `null` when:
     * - The record no longer resolves via the field-object resolver seam
     *   (mirrors {@see buildDescriptor}).
     * - The field type is no longer relationship / post_object.
     * - The current user fails the same option-owned capability probe the
     *   record's `visibleTo` uses (R4 mockup DESIGN-DECISIONS §5's
     *   "recheck capability against the owner").
     * - The stored option value is empty or contains no valid post ids.
     *
     * @param ControlRecord $record
     * @param string        $sessionId  Unused for Shared Globals — options
     *                                   are session-agnostic — but kept as a
     *                                   parameter to satisfy the interface.
     * @return array<string, mixed>|null
     */
    public function buildValueSummary(ControlRecord $record, $sessionId)
    {
        unset($sessionId);

        $field_name = isset($record->source['field_name']) ? sanitize_key((string) $record->source['field_name']) : '';
        if ($field_name === '') {
            return null;
        }

        $field = call_user_func($this->fieldObjectResolver, $field_name);
        if (! is_array($field)) {
            return null;
        }

        $resolved_name = isset($field['name']) ? sanitize_key((string) $field['name']) : '';
        if ($resolved_name === '' || $resolved_name !== $field_name) {
            return null;
        }

        $field_type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
        if (! in_array($field_type, ['relationship', 'post_object'], true)) {
            return null;
        }

        // Recheck capability against the same options-owned probe descriptor
        // the visibility closure uses at list-time. This closes the "list
        // included, but stored value snuck out via the summary endpoint"
        // window that DESIGN-DECISIONS §5 calls out.
        $probe = new EditableDescriptor(
            've_shared_global_capability_probe',
            'editable',
            'shared_entity',
            [
                'type' => 'option',
                'id' => 0,
                'subtype' => 'acf_options',
                'acf_object_id' => 'option',
            ],
            [],
            [],
            [],
            []
        );
        if (! $this->capabilities->canEditDescriptor($probe)) {
            return null;
        }

        $value = call_user_func($this->optionValueResolver, $field_name);
        $ids = $this->normalizeToPostIds($value);
        if (empty($ids)) {
            return null;
        }

        $limit = 3;
        $preview_ids = array_slice($ids, 0, $limit);
        $titles = [];
        foreach ($preview_ids as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id <= 0) {
                continue;
            }
            if (! function_exists('get_the_title')) {
                continue;
            }
            $title = get_the_title($post_id);
            if (! is_string($title) || $title === '') {
                continue;
            }
            $titles[] = sanitize_text_field($title);
        }
        if (empty($titles)) {
            return null;
        }

        return [
            'family' => $field_type,
            'count' => count($ids),
            'firstTitles' => $titles,
            'hasMore' => count($ids) > count($titles),
        ];
    }

    /**
     * R4-A — coerce whatever `get_field($name, 'option', ...)` returned into
     * a flat list of positive post ids. Accepts ints, numeric strings,
     * WP_Post objects, ACF's associative-array shape (`['ID' => 123, …]`),
     * or a single scalar (post_object). Anything that does not resolve is
     * dropped rather than throwing.
     *
     * @param mixed $value
     * @return array<int, int>
     */
    private function normalizeToPostIds($value)
    {
        if ($value === null || $value === false || $value === '') {
            return [];
        }

        if (! is_array($value)) {
            $id = $this->coerceOnePostId($value);

            return $id > 0 ? [$id] : [];
        }

        $ids = [];
        foreach ($value as $item) {
            $id = $this->coerceOnePostId($item);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @param mixed $item
     * @return int
     */
    private function coerceOnePostId($item)
    {
        if (is_object($item) && isset($item->ID) && is_numeric($item->ID)) {
            return (int) $item->ID;
        }
        if (is_array($item) && isset($item['ID']) && is_numeric($item['ID'])) {
            return (int) $item['ID'];
        }
        if (is_numeric($item)) {
            return (int) $item;
        }

        return 0;
    }

    /**
     * Walk the ACF field's parent chain to find its containing field group,
     * then return the group's human title. Mirrors
     * `SharedGlobalFieldsController::resolveFieldGroupKey` +
     * `resolveFieldGroupContext` so the record's `group` display value is
     * consistent with the existing Shared Globals popover UI. Returns an
     * empty string when ACF is unavailable or the group cannot be resolved.
     *
     * @param array<string, mixed> $field
     * @return string
     */
    private function resolveFieldGroupTitle(array $field)
    {
        $group_key = $this->resolveFieldGroupKey($field);
        if ($group_key === '' || ! function_exists('acf_get_field_group')) {
            return '';
        }

        $group = acf_get_field_group($group_key);
        if (! is_array($group) || empty($group['title'])) {
            return '';
        }

        return sanitize_text_field((string) $group['title']);
    }

    /**
     * @param array<string, mixed> $field
     * @return string
     */
    private function resolveFieldGroupKey(array $field)
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
}
