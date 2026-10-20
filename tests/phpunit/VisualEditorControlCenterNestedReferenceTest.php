<?php

use Dbvc\VisualEditor\Registry\Providers\SharedGlobalsDescriptorFactory;

// Stub `acf_get_field` so `SharedGlobalsDescriptorFactory::walkGroupChain`
// can walk a synthetic parent chain without loading ACF. The stub reads
// from `$GLOBALS['vfe_test_acf_fields']`, populated per-test in set_up().
if (! function_exists('acf_get_field')) {
    function acf_get_field($selector)
    {
        $fields = isset($GLOBALS['vfe_test_acf_fields']) && is_array($GLOBALS['vfe_test_acf_fields'])
            ? $GLOBALS['vfe_test_acf_fields']
            : [];
        return isset($fields[$selector]) && is_array($fields[$selector])
            ? $fields[$selector]
            : null;
    }
}

/**
 * Nested reference-collection slice — factory `group_write_path` emission
 * for group-nested relationship / post_object fields.
 *
 * Extends `SharedGlobalsDescriptorFactory`'s relationship branch to walk
 * the ACF parent chain via `walkGroupChain` (same helper R5.1-b's
 * text-family branch uses) and emit `source.group_write_path` +
 * `source.is_grouped_field=true` + root `field_selector` when the leaf
 * is nested. `AcfReferenceCollectionResolver::writeCollectionValue()` →
 * `writeAcfValue()` → `writeGroupedFieldValue()` picks up those keys and
 * routes the save THROUGH the parent group's `update_value` (Media
 * Manager R2-F RK-013 pattern).
 *
 * Top-level relationship / post_object records see an empty chain and
 * fall through to the R3-C-1 shape unchanged — this is asserted via a
 * regression test that pins the top-level shape byte-identical.
 *
 * The "gate on group-only" check lives in the Vertical provider's
 * resolveStatus (see VF_Vertical_Control_Provider::hasGroupOnlyAncestry)
 * — a repeater-nested reference collection would need
 * writeRepeaterSubfieldValue, not writeGroupedFieldValue.
 */
final class VisualEditorControlCenterNestedReferenceTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        $GLOBALS['vfe_test_acf_fields'] = [];
    }

    public function tear_down(): void
    {
        unset($GLOBALS['vfe_test_acf_fields']);
        parent::tear_down();
    }

    public function test_top_level_relationship_descriptor_emits_no_group_write_path(): void
    {
        // Regression guard — a leaf with parent that's a field group
        // (`group_XXX`) is TOP-LEVEL. Descriptor stays byte-identical to
        // the R3-C-1 shape.
        $GLOBALS['vfe_test_acf_fields'] = [];

        $factory = new SharedGlobalsDescriptorFactory();
        $descriptor = $factory->build('ves_test', [], [
            'name' => 'featured_posts',
            'key' => 'field_featured_posts',
            'type' => 'relationship',
            'label' => 'Featured posts',
            'parent' => 'group_top_level', // field-group parent → top-level
            'multiple' => 1,
            'post_type' => ['post'],
        ]);

        $this->assertSame('acf_collection_field', $descriptor->source['type']);
        $this->assertSame('shared_relationship_collection', $descriptor->mutation['contract']);
        // Top-level: no group nesting keys.
        $this->assertArrayNotHasKey('group_write_path', $descriptor->source);
        $this->assertArrayNotHasKey('is_grouped_field', $descriptor->source);
        // field_selector still equals the leaf name.
        $this->assertSame('featured_posts', $descriptor->source['field_selector']);
        $this->assertSame('featured_posts', $descriptor->path['rootFieldName']);
    }

    public function test_group_nested_relationship_descriptor_emits_group_write_path(): void
    {
        // Simulated chain: field_root_group (name: settings_nav_menus) →
        // field_leaf (name: menu_posts). Leaf's parent points at
        // field_root_group; field_root_group's parent points at
        // group_XXX (field-group boundary — the walk stops there).
        $GLOBALS['vfe_test_acf_fields'] = [
            'field_root_group' => [
                'key' => 'field_root_group',
                'name' => 'settings_nav_menus',
                'type' => 'group',
                'parent' => 'group_nav_menus',
            ],
        ];

        $factory = new SharedGlobalsDescriptorFactory();
        $descriptor = $factory->build('ves_test', [], [
            'name' => 'menu_posts',
            'key' => 'field_leaf',
            'type' => 'relationship',
            'label' => 'Menu posts',
            'parent' => 'field_root_group', // nested inside a group
            'multiple' => 1,
        ]);

        $this->assertSame('acf_collection_field', $descriptor->source['type']);
        $this->assertSame('shared_relationship_collection', $descriptor->mutation['contract']);
        // Nested keys emitted.
        $this->assertTrue($descriptor->source['is_grouped_field']);
        $this->assertIsArray($descriptor->source['group_write_path']);
        $this->assertCount(1, $descriptor->source['group_write_path']);
        $this->assertSame('field_leaf', $descriptor->source['group_write_path'][0]['key']);
        $this->assertSame('menu_posts', $descriptor->source['group_write_path'][0]['name']);
        // field_selector points at the ROOT group so
        // AbstractAcfResolver::writeGroupedFieldValue routes the save
        // through the root group's update_value.
        $this->assertSame('settings_nav_menus', $descriptor->source['field_selector']);
        $this->assertSame('settings_nav_menus', $descriptor->source['field_selector_raw']);
        // Leaf identity preserved for the summary + display path.
        $this->assertSame('menu_posts', $descriptor->source['leaf_field_name']);
        $this->assertSame('field_leaf', $descriptor->source['leaf_field_key']);
        // path.rootField* points at the root group.
        $this->assertSame('settings_nav_menus', $descriptor->path['rootFieldName']);
        $this->assertSame('field_root_group', $descriptor->path['rootFieldKey']);
    }

    public function test_multi_level_group_chain_walks_every_link(): void
    {
        // Three-deep chain: outer_group → inner_group → leaf.
        $GLOBALS['vfe_test_acf_fields'] = [
            'field_outer_group' => [
                'key' => 'field_outer_group',
                'name' => 'brand_settings',
                'type' => 'group',
                'parent' => 'group_brand',
            ],
            'field_inner_group' => [
                'key' => 'field_inner_group',
                'name' => 'palette',
                'type' => 'group',
                'parent' => 'field_outer_group',
            ],
        ];

        $factory = new SharedGlobalsDescriptorFactory();
        $descriptor = $factory->build('ves_test', [], [
            'name' => 'featured_swatches',
            'key' => 'field_leaf_swatches',
            'type' => 'post_object',
            'label' => 'Featured swatches',
            'parent' => 'field_inner_group',
        ]);

        // group_write_path = [inner, leaf] (root is at chain[0] and lands
        // in field_selector, not the write path).
        $this->assertSame('brand_settings', $descriptor->source['field_selector']);
        $this->assertCount(2, $descriptor->source['group_write_path']);
        $this->assertSame('palette', $descriptor->source['group_write_path'][0]['name']);
        $this->assertSame('featured_swatches', $descriptor->source['group_write_path'][1]['name']);
        $this->assertSame('shared_post_object_collection', $descriptor->mutation['contract']);
    }
}
