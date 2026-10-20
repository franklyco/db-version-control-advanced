<?php

use Dbvc\VisualEditor\Registry\ControlRegistry;
use Dbvc\VisualEditor\Registry\Providers\SharedGlobalsDescriptorFactory;

// Stub `acf_get_field` for the nested-reference + R5.7-a test cases in
// this file. Same fixture-registry pattern the NestedReference test
// uses — both files share the `$GLOBALS['vfe_test_acf_fields']` map, and
// PHP defines the function only once regardless of load order.
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

// R5.7-a — stub `get_field` for the repeater-unroll test cases. Reads
// from `$GLOBALS['vfe_test_acf_option_rows']` keyed by field selector
// (name or key). Returns null for unknown selectors so tests that don't
// seed rows keep the R5.6 "no expansion when ACF stack is unavailable"
// behavior. Options-context only ($object_id === 'option').
if (! function_exists('get_field')) {
    function get_field($selector, $object_id = false, $format = true)
    {
        unset($format);
        if ($object_id !== 'option') {
            return null;
        }
        $rows = isset($GLOBALS['vfe_test_acf_option_rows']) && is_array($GLOBALS['vfe_test_acf_option_rows'])
            ? $GLOBALS['vfe_test_acf_option_rows']
            : [];
        return isset($rows[$selector]) ? $rows[$selector] : null;
    }
}

/**
 * R5.1-b — Vertical cross-repo unlock.
 *
 * Covers the pure-PHP claims R5.1-b makes about the theme-side
 * `VF_Vertical_Control_Provider` class:
 *
 *   1. mapRecord flips `status='available'` for R5.1 text-family records
 *      (unlocks_at='R5.1' AND field_type ∈ text/textarea/url/email/number).
 *   2. Every other record — including R5.1 records with non-text-family
 *      field_type, and text-family records with a different unlocks_at
 *      tag — stays `status='unsupported'`.
 *   3. buildDescriptor returns null for unsupported records (drawer render
 *      contract: no Open button → open route never called).
 *
 * R5.6 hardening extends this file's coverage to include the shared
 * parent-chain gate (`parentChainIsGroupOnly`) that now applies to EVERY
 * unlocked family for nested records. Each existing nested-family test
 * therefore seeds a group-only ACF stub chain via `seedGroupOnlyChain()`
 * so the gate can walk successfully; a matrix test
 * (`test_repeater_in_chain_blocks_every_unlocked_family`) asserts the
 * opposite direction — a repeater intermediate short-circuits any
 * unlocked family back to `unsupported`.
 *
 * The buildDescriptor happy-path (available record → SharedGlobalsDescriptorFactory
 * → EditableDescriptor) requires ACF's `get_field_object` and `acf_get_field`,
 * which the WP test harness does not load. That path is exercised at runtime
 * on the live site; here we only assert that the delegation SITE is wired
 * (null return for unsupported; short-circuit before ACF calls when
 * function_exists guards fire).
 *
 * The Vertical file lives in a sibling theme checkout. Tests skip when the
 * file isn't present so CI environments without the theme still pass.
 */
final class VisualEditorVerticalProviderR51BTest extends WP_UnitTestCase
{
    /**
     * @var string
     */
    private $vertical_provider_path;

    public function set_up(): void
    {
        parent::set_up();
        // __DIR__ = .../wp-content/plugins/db-version-control-main/tests/phpunit
        // 4 dirname() calls → .../wp-content, then jump into themes/vertical.
        $this->vertical_provider_path = dirname(__DIR__, 4)
            . '/themes/vertical/functions/features/dbvc-visual-editor/includes/class-vf-vertical-control-provider.php';

        if (! is_readable($this->vertical_provider_path)) {
            $this->markTestSkipped(
                'Vertical theme file not present at: ' . $this->vertical_provider_path
            );
        }
        require_once $this->vertical_provider_path;
        if (! class_exists('VF_Vertical_Control_Provider')) {
            $this->markTestSkipped('VF_Vertical_Control_Provider not loadable.');
        }
        // Reset the ACF stub registry so nested-reference test methods
        // start with a clean map (each test that needs stubbed fields
        // populates $GLOBALS directly).
        $GLOBALS['vfe_test_acf_fields'] = [];
        $GLOBALS['vfe_test_acf_option_rows'] = [];
    }

    public function tear_down(): void
    {
        unset($GLOBALS['vfe_test_acf_fields'], $GLOBALS['vfe_test_acf_option_rows']);
        parent::tear_down();
    }

    // ---------------------------------------------------------------------
    // 1. mapRecord — R5.1 text-family flips to available
    // ---------------------------------------------------------------------

    /**
     * @dataProvider r51TextFamilyProvider
     */
    public function test_r51_text_family_record_maps_to_available_status(string $field_type): void
    {
        // R5.6: nested records now walk the ACF parent chain. Seed a
        // group-only ancestry so the gate passes and the family stays
        // the focus of this assertion.
        $this->seedGroupOnlyChain('field_sample_leaf', $field_type);

        $records = [
            [
                'id' => 'sample__group__leaf',
                'label' => 'Sample',
                'field_name' => 'sample_group>leaf_field',
                'field_key' => 'field_sample_leaf',
                'field_type' => $field_type,
                'unlocks_at' => 'R5.1',
                'category' => 'Content',
                'group_title' => 'Sample Group',
                'owner_subtype' => 'site-settings',
                'client_priority' => 'must',
                'notes' => '',
            ],
        ];

        $provider = $this->makeProviderWithRecords($records);
        $items = $provider->getControls();

        $this->assertCount(1, $items);
        $this->assertSame('available', $items[0]['status']);
        $this->assertSame('text', $items[0]['fieldFamily']);
        // R5.1-b: source carries field_type + unlocks_at so buildDescriptor
        // + buildValueSummary can gate + resolve without re-reading the
        // curation JSON.
        $this->assertSame($field_type, $items[0]['source']['field_type']);
        $this->assertSame('R5.1', $items[0]['source']['unlocks_at']);
    }

    public function r51TextFamilyProvider(): array
    {
        return [
            'text' => ['text'],
            'textarea' => ['textarea'],
            'url' => ['url'],
            'email' => ['email'],
            'number' => ['number'],
        ];
    }

    public function test_r55_date_picker_record_maps_to_available_status(): void
    {
        // R5.5: date_picker rides the R5.1 unlocks_at slot (it's a
        // text-family-adjacent primitive on the same shared_field
        // mutation contract). resolveStatus's R5.1 branch was widened
        // to include date_picker. Vertical's mapFieldFamily has no
        // date_picker case so it falls to 'other' — that's the drawer
        // field-family filter bucket, not a functional constraint.
        $this->seedGroupOnlyChain('field_business_anniversary', 'date_picker');

        $records = [
            [
                'id' => 'admin_site_verbiage__site_business_type',
                'label' => 'Site business type',
                'field_name' => 'admin_site_verbiage>site_business_type',
                'field_key' => 'field_business_anniversary',
                'field_type' => 'date_picker',
                'unlocks_at' => 'R5.1',
                'category' => 'Content',
                'group_title' => 'Site Verbiage',
                'owner_subtype' => 'admin-settings',
                'client_priority' => 'must',
                'notes' => '',
            ],
        ];

        $provider = $this->makeProviderWithRecords($records);
        $items = $provider->getControls();

        $this->assertCount(1, $items);
        $this->assertSame('available', $items[0]['status']);
        $this->assertSame('other', $items[0]['fieldFamily']);
        $this->assertSame('date_picker', $items[0]['source']['field_type']);
        $this->assertSame('R5.1', $items[0]['source']['unlocks_at']);
    }

    public function test_r51_non_text_family_record_stays_unsupported(): void
    {
        // color_picker is R5.2+color_picker territory — even with
        // unlocks_at="R5.1" on the record (which would be a curation
        // mismatch), the resolveStatus gate must guard on field_type too
        // and NOT flip to available.
        $records = [
            [
                'id' => 'brand__palette__accent',
                'label' => 'Accent',
                'field_name' => 'brand>palette>accent',
                'field_key' => 'field_brand_accent',
                'field_type' => 'color_picker',
                'unlocks_at' => 'R5.1', // deliberate mismatch
                'category' => 'Brand',
                'group_title' => 'Palette',
                'owner_subtype' => 'brand-settings',
                'client_priority' => 'should',
                'notes' => '',
            ],
        ];

        $provider = $this->makeProviderWithRecords($records);
        $items = $provider->getControls();

        $this->assertCount(1, $items);
        $this->assertSame('unsupported', $items[0]['status']);
    }

    // ---------------------------------------------------------------------
    // R5.2 additions — choice / link / wysiwyg families flip to available
    // ---------------------------------------------------------------------

    /**
     * @dataProvider r52FamilyProvider
     */
    public function test_r52_family_record_maps_to_available_status(string $field_type): void
    {
        $this->seedGroupOnlyChain('field_sample_leaf', $field_type);

        $records = [
            [
                'id' => 'sample__group__leaf',
                'label' => 'Sample',
                'field_name' => 'sample_group>leaf',
                'field_key' => 'field_sample_leaf',
                'field_type' => $field_type,
                'unlocks_at' => 'R5.2',
                'category' => 'Brand',
                'group_title' => 'Sample',
                'owner_subtype' => 'brand-settings',
                'client_priority' => 'must',
                'notes' => '',
            ],
        ];

        $provider = $this->makeProviderWithRecords($records);
        $items = $provider->getControls();

        $this->assertCount(1, $items);
        $this->assertSame('available', $items[0]['status']);
        $this->assertSame($field_type, $items[0]['source']['field_type']);
    }

    public function r52FamilyProvider(): array
    {
        return [
            'select' => ['select'],
            'checkbox' => ['checkbox'],
            'radio' => ['radio'],
            'button_group' => ['button_group'],
            'link' => ['link'],
            'wysiwyg' => ['wysiwyg'],
        ];
    }

    // ---------------------------------------------------------------------
    // Nested reference-collection: group-only ACF ancestry gate
    // ---------------------------------------------------------------------

    public function test_nested_reference_group_only_ancestry_flips_available(): void
    {
        // Simulated chain: field_root_group (type=group, parent=field-group)
        // → field_menu_posts (type=relationship, parent=field_root_group).
        // hasGroupOnlyAncestry() should see every intermediate is a group
        // and flip to available.
        $GLOBALS['vfe_test_acf_fields'] = [
            'field_menu_posts' => [
                'key' => 'field_menu_posts',
                'name' => 'menu_posts',
                'type' => 'relationship',
                'parent' => 'field_root_group',
            ],
            'field_root_group' => [
                'key' => 'field_root_group',
                'name' => 'settings_nav_menus',
                'type' => 'group',
                'parent' => 'group_nav_menus',
            ],
        ];

        $records = [
            [
                'id' => 'nav__menu_posts',
                'label' => 'Menu posts',
                'field_name' => 'settings_nav_menus>menu_posts',
                'field_key' => 'field_menu_posts',
                'field_type' => 'relationship',
                'unlocks_at' => 'R5.4',
                'category' => 'Layout',
                'group_title' => 'Nav menus',
                'owner_subtype' => 'advanced-settings',
                'client_priority' => 'nice',
                'notes' => '',
            ],
        ];

        $provider = $this->makeProviderWithRecords($records);
        $items = $provider->getControls();

        $this->assertCount(1, $items);
        $this->assertSame('available', $items[0]['status']);
    }

    public function test_nested_reference_repeater_in_chain_stays_unsupported(): void
    {
        // Simulated chain: field_root_group → field_repeater (type=repeater!)
        // → field_menu_posts. hasGroupOnlyAncestry() must reject on the
        // non-group intermediate.
        $GLOBALS['vfe_test_acf_fields'] = [
            'field_menu_posts' => [
                'key' => 'field_menu_posts',
                'name' => 'menu_posts',
                'type' => 'relationship',
                'parent' => 'field_repeater',
            ],
            'field_repeater' => [
                'key' => 'field_repeater',
                'name' => 'menus',
                'type' => 'repeater',
                'parent' => 'field_root_group',
            ],
            'field_root_group' => [
                'key' => 'field_root_group',
                'name' => 'settings_nav_menus',
                'type' => 'group',
                'parent' => 'group_nav_menus',
            ],
        ];

        $records = [
            [
                'id' => 'nav__menus__menu_posts',
                'label' => 'Menu posts',
                'field_name' => 'settings_nav_menus>menus>menu_posts',
                'field_key' => 'field_menu_posts',
                'field_type' => 'relationship',
                'unlocks_at' => 'R5.4',
                'category' => 'Layout',
                'group_title' => 'Nav menus',
                'owner_subtype' => 'advanced-settings',
                'client_priority' => 'nice',
                'notes' => '',
            ],
        ];

        $provider = $this->makeProviderWithRecords($records);
        $items = $provider->getControls();

        $this->assertCount(1, $items);
        $this->assertSame('unsupported', $items[0]['status']);
    }

    // ---------------------------------------------------------------------
    // R5.4 additions — post_object / relationship (top-level only)
    // ---------------------------------------------------------------------

    /**
     * @dataProvider r54FamilyProvider
     */
    public function test_r54_top_level_reference_record_maps_to_available(string $field_type): void
    {
        // Top-level (no `>` in field_name) — R3-C-1 reference-collection
        // factory branch handles this correctly; R5.4 flips it to
        // available.
        $records = [
            [
                'id' => 'sample__office_manager',
                'label' => 'Office Manager',
                'field_name' => 'office_manager',
                'field_key' => 'field_office_manager',
                'field_type' => $field_type,
                'unlocks_at' => 'R5.4',
                'category' => 'Contact',
                'group_title' => 'Team',
                'owner_subtype' => 'site-settings',
                'client_priority' => 'must',
                'notes' => '',
            ],
        ];

        $provider = $this->makeProviderWithRecords($records);
        $items = $provider->getControls();

        $this->assertCount(1, $items);
        $this->assertSame('available', $items[0]['status']);
        $this->assertSame($field_type, $items[0]['fieldFamily']);
    }

    public function r54FamilyProvider(): array
    {
        return [
            'post_object' => ['post_object'],
            'relationship' => ['relationship'],
        ];
    }

    public function test_r54_nested_reference_record_stays_unsupported(): void
    {
        // Post-nested-reference: R3-C-1's factory branch DOES emit
        // group_write_path when the leaf's ancestry is group-only, but
        // a repeater in the chain must still short to unsupported —
        // writeGroupedFieldValue can't address repeater rows. Seed a
        // repeater-in-chain fixture to exercise that path.
        $this->seedRepeaterInChain('field_menu_posts', 'relationship');

        $records = [
            [
                'id' => 'nav__menus__menu_posts',
                'label' => 'Menu posts',
                'field_name' => 'settings_nav_menus>menus>menu_posts',
                'field_key' => 'field_menu_posts',
                'field_type' => 'relationship',
                'unlocks_at' => 'R5.4',
                'category' => 'Layout',
                'group_title' => 'Nav menus',
                'owner_subtype' => 'advanced-settings',
                'client_priority' => 'nice',
                'notes' => '',
            ],
        ];

        $provider = $this->makeProviderWithRecords($records);
        $items = $provider->getControls();

        $this->assertCount(1, $items);
        $this->assertSame('unsupported', $items[0]['status']);
    }

    public function test_r52_color_picker_record_maps_to_available_status(): void
    {
        // R5.2+color_picker: curation JSON tags the 20 color_picker
        // records with unlocks_at="R5.2+color_picker". resolveStatus flips
        // them to available now that AcfColorPickerResolver ships.
        $this->seedGroupOnlyChain('field_brand_palette_accent', 'color_picker');

        $records = [
            [
                'id' => 'brand__palette__accent',
                'label' => 'Accent color',
                'field_name' => 'brand>palette>accent',
                'field_key' => 'field_brand_palette_accent',
                'field_type' => 'color_picker',
                'unlocks_at' => 'R5.2+color_picker',
                'category' => 'Brand',
                'group_title' => 'Palette',
                'owner_subtype' => 'brand-settings',
                'client_priority' => 'must',
                'notes' => '',
            ],
        ];

        $provider = $this->makeProviderWithRecords($records);
        $items = $provider->getControls();

        $this->assertCount(1, $items);
        $this->assertSame('available', $items[0]['status']);
        $this->assertSame('color_picker', $items[0]['source']['field_type']);
    }

    public function test_r53_image_record_maps_to_available_status(): void
    {
        // R5.3: image field with unlocks_at="R5.3" should flip to
        // available. resolveStatus must gate on BOTH conditions.
        $this->seedGroupOnlyChain('field_brand_primary_logo', 'image');

        $records = [
            [
                'id' => 'brand__identity__primary_logo',
                'label' => 'Primary logo',
                'field_name' => 'brand>identity>primary_logo',
                'field_key' => 'field_brand_primary_logo',
                'field_type' => 'image',
                'unlocks_at' => 'R5.3',
                'category' => 'Brand',
                'group_title' => 'Identity',
                'owner_subtype' => 'brand-settings',
                'client_priority' => 'must',
                'notes' => '',
            ],
        ];

        $provider = $this->makeProviderWithRecords($records);
        $items = $provider->getControls();

        $this->assertCount(1, $items);
        $this->assertSame('available', $items[0]['status']);
        $this->assertSame('image', $items[0]['fieldFamily']);
    }

    public function test_r52_true_false_now_flips_to_available(): void
    {
        // true_false originally deferred (no boolean controller). Its
        // dedicated slice added AcfTrueFalseResolver + createBooleanController,
        // so curated `unlocks_at="R5.2"` + `field_type="true_false"` now
        // flips to available.
        $this->seedGroupOnlyChain('field_flag_example', 'true_false');

        $records = [
            [
                'id' => 'flag__example',
                'label' => 'Example flag',
                'field_name' => 'flag>example',
                'field_key' => 'field_flag_example',
                'field_type' => 'true_false',
                'unlocks_at' => 'R5.2',
                'category' => 'Content',
                'group_title' => 'Flags',
                'owner_subtype' => 'site-settings',
                'client_priority' => 'nice',
                'notes' => '',
            ],
        ];

        $provider = $this->makeProviderWithRecords($records);
        $items = $provider->getControls();

        $this->assertCount(1, $items);
        $this->assertSame('available', $items[0]['status']);
        $this->assertSame('true_false', $items[0]['source']['field_type']);
    }

    public function test_text_family_record_with_later_unlocks_at_stays_unsupported(): void
    {
        // Text-family field but tagged for a later R5.x slice — the
        // resolveStatus gate demands BOTH conditions.
        $records = [
            [
                'id' => 'brand__voice__tagline',
                'label' => 'Brand Tagline',
                'field_name' => 'brand>voice>tagline',
                'field_key' => 'field_brand_tagline',
                'field_type' => 'text',
                'unlocks_at' => 'R5.2', // future slice
                'category' => 'Brand',
                'group_title' => 'Voice',
                'owner_subtype' => 'brand-settings',
                'client_priority' => 'must',
                'notes' => '',
            ],
        ];

        $provider = $this->makeProviderWithRecords($records);
        $items = $provider->getControls();

        $this->assertCount(1, $items);
        $this->assertSame('unsupported', $items[0]['status']);
    }

    public function test_r5later_pathb_never_unlocks_at_stays_unsupported(): void
    {
        // R5.later Path B (2026-09-03): `unlocks_at="never"` is the
        // sentinel FieldCurationRecommender emits for `vf_palette` to
        // mark it permanently out-of-scope for the drawer. This
        // sentinel flows through resolveStatus's default
        // "unrecognized unlocks_at → unsupported" path — no explicit
        // handling needed in the provider, but this defensive test
        // pins the behaviour so a future contributor who adds a
        // `unlocks_at="never" → available` bypass without checking the
        // Path B rationale trips this test.
        $records = [
            [
                'id' => 'advanced_settings__settings_vf_term_colors',
                'label' => 'Term Colors',
                'field_name' => 'settings_vf_term_colors',
                'field_key' => 'field_68d21458a392d',
                'field_type' => 'vf_palette',
                'unlocks_at' => 'never',
                'category' => 'Brand',
                'group_title' => 'Site Settings Advanced',
                'owner_subtype' => 'advanced-settings',
                'client_priority' => 'nice',
                'notes' => '',
            ],
        ];

        $provider = $this->makeProviderWithRecords($records);
        $items = $provider->getControls();

        $this->assertCount(1, $items);
        $this->assertSame('unsupported', $items[0]['status']);
        // Field family maps to 'other' (vf_palette isn't in the
        // ControlRecord whitelist — falls through to 'other').
        $this->assertSame('other', $items[0]['fieldFamily']);
        // Source carries the sentinel unchanged so downstream tooling
        // (e.g. curation-drift audits) can distinguish "intentionally
        // never" from "not-yet-supported (later)".
        $this->assertSame('never', $items[0]['source']['unlocks_at']);
    }

    // ---------------------------------------------------------------------
    // 2. buildDescriptor — unsupported short-circuits to null
    // ---------------------------------------------------------------------

    public function test_build_descriptor_returns_null_for_unsupported_record(): void
    {
        // Repeater-in-chain nested reference — R5.6 hardening's
        // shared parentChainIsGroupOnly gate rejects it. buildDescriptor
        // must return null so the drawer never surfaces an Open button.
        $this->seedRepeaterInChain('field_menu_posts', 'relationship');

        $records = [
            [
                'id' => 'nav__menus__menu_posts',
                'label' => 'Menu posts',
                'field_name' => 'settings_nav_menus>menus>menu_posts',
                'field_key' => 'field_menu_posts',
                'field_type' => 'relationship',
                'unlocks_at' => 'R5.4',
                'category' => 'Layout',
                'group_title' => 'Nav menus',
                'owner_subtype' => 'advanced-settings',
                'client_priority' => 'nice',
                'notes' => '',
            ],
        ];

        $provider = $this->makeProviderWithRecords($records);
        $registry = new ControlRegistry();
        $registry->registerProvider($provider);
        $record = $registry->getVisibleRecord('vertical:nav__menus__menu_posts');
        $this->assertNotNull($record);
        $this->assertSame('unsupported', $record->status);
        $this->assertNull(
            $provider->buildDescriptor($record, 'ves_test', [])
        );
    }

    // ---------------------------------------------------------------------
    // 3. buildValueSummary — unsupported short-circuits to null
    // ---------------------------------------------------------------------

    public function test_build_value_summary_returns_null_for_unsupported_record(): void
    {
        // Repeater-in-chain nested reference: buildValueSummary short-
        // circuits to null the same way buildDescriptor does, so the
        // value-summary batch never renders a stale chip.
        $this->seedRepeaterInChain('field_menu_posts', 'relationship');

        $records = [
            [
                'id' => 'nav__menus__menu_posts',
                'label' => 'Menu posts',
                'field_name' => 'settings_nav_menus>menus>menu_posts',
                'field_key' => 'field_menu_posts',
                'field_type' => 'relationship',
                'unlocks_at' => 'R5.4',
                'category' => 'Layout',
                'group_title' => 'Nav menus',
                'owner_subtype' => 'advanced-settings',
                'client_priority' => 'nice',
                'notes' => '',
            ],
        ];

        $provider = $this->makeProviderWithRecords($records);
        $registry = new ControlRegistry();
        $registry->registerProvider($provider);
        $record = $registry->getVisibleRecord('vertical:nav__menus__menu_posts');
        $this->assertNotNull($record);
        $this->assertNull(
            $provider->buildValueSummary($record, 'ves_test')
        );
    }

    // ---------------------------------------------------------------------
    // R5.6 hardening — repeater-in-chain blocks every unlocked family
    // ---------------------------------------------------------------------

    /**
     * Matrix sweep: any nested record whose ACF ancestry contains a
     * Repeater intermediate must stay `unsupported` regardless of the
     * unlocked family. This is the R5.6 hardening's central claim —
     * the shared `parentChainIsGroupOnly` gate applies to every family
     * (text, choice, link, wysiwyg, true_false, image, color_picker,
     * relationship, post_object) — not only R5.4 reference collections.
     *
     * @dataProvider unlockedFamilyMatrixProvider
     */
    public function test_repeater_in_chain_blocks_every_unlocked_family(
        string $unlocks_at,
        string $field_type
    ): void {
        $this->seedRepeaterInChain('field_matrix_leaf', $field_type);

        $records = [
            [
                'id' => 'matrix__' . $field_type,
                'label' => 'Matrix ' . $field_type,
                'field_name' => 'matrix_root>matrix_row>leaf',
                'field_key' => 'field_matrix_leaf',
                'field_type' => $field_type,
                'unlocks_at' => $unlocks_at,
                'category' => 'Matrix',
                'group_title' => 'Matrix',
                'owner_subtype' => 'site-settings',
                'client_priority' => 'nice',
                'notes' => '',
            ],
        ];

        $provider = $this->makeProviderWithRecords($records);
        $items = $provider->getControls();

        $this->assertCount(1, $items);
        $this->assertSame(
            'unsupported',
            $items[0]['status'],
            sprintf(
                'Expected repeater-in-chain to short %s (%s) to unsupported.',
                $field_type,
                $unlocks_at
            )
        );
    }

    public function unlockedFamilyMatrixProvider(): array
    {
        return [
            // R5.1 text-family (5)
            'R5.1 text' => ['R5.1', 'text'],
            'R5.1 textarea' => ['R5.1', 'textarea'],
            'R5.1 url' => ['R5.1', 'url'],
            'R5.1 email' => ['R5.1', 'email'],
            'R5.1 number' => ['R5.1', 'number'],
            // R5.5 date_picker rides the R5.1 slot
            'R5.1 date_picker' => ['R5.1', 'date_picker'],
            // R5.2 choice / link / wysiwyg / true_false (7)
            'R5.2 select' => ['R5.2', 'select'],
            'R5.2 checkbox' => ['R5.2', 'checkbox'],
            'R5.2 radio' => ['R5.2', 'radio'],
            'R5.2 button_group' => ['R5.2', 'button_group'],
            'R5.2 link' => ['R5.2', 'link'],
            'R5.2 wysiwyg' => ['R5.2', 'wysiwyg'],
            'R5.2 true_false' => ['R5.2', 'true_false'],
            // R5.3 image (1)
            'R5.3 image' => ['R5.3', 'image'],
            // R5.2+color_picker (1)
            'R5.2+color_picker color_picker' => ['R5.2+color_picker', 'color_picker'],
            // R5.4 reference collections (2)
            'R5.4 relationship' => ['R5.4', 'relationship'],
            'R5.4 post_object' => ['R5.4', 'post_object'],
        ];
    }

    // ---------------------------------------------------------------------
    // R5.7-a — Repeater-nested curation unroll into parent + N leaves
    // ---------------------------------------------------------------------

    public function test_repeater_nested_record_expands_to_parent_plus_n_leaves(): void
    {
        // Seed a repeater chain: field_menu_posts (relationship) has
        // parent field_menus (repeater), which lives under group_nav_menus.
        $this->seedRepeaterChain('field_menu_posts', 'relationship', 'field_menus', 'menus');
        // Seed 3 rows in the `menus` repeater on the options page.
        $GLOBALS['vfe_test_acf_option_rows']['field_menus'] = [
            ['menu_title' => 'Primary', 'menu_posts' => [1, 2, 3]],
            ['menu_title' => 'Footer', 'menu_posts' => [4, 5]],
            ['menu_title' => 'Utility', 'menu_posts' => [6]],
        ];

        $records = [
            [
                'id' => 'nav__menus__menu_posts',
                'label' => 'Menu posts',
                'field_name' => 'settings_nav_menus>menus>menu_posts',
                'field_key' => 'field_menu_posts',
                'field_type' => 'relationship',
                'unlocks_at' => 'R5.4',
                'category' => 'Layout',
                'group_title' => 'Nav menus',
                'owner_subtype' => 'advanced-settings',
                'client_priority' => 'nice',
                'notes' => '',
            ],
        ];

        $provider = $this->makeProviderWithRecords($records);
        $items = $provider->getControls();

        // 1 parent + 3 leaves = 4 total records.
        $this->assertCount(4, $items);

        // First item should be the parent (repeater_parent) with the
        // right meta shape.
        $parent = $items[0];
        $this->assertSame('repeater_menus', $parent['id']);
        $this->assertSame('unsupported', $parent['status']);
        $this->assertSame('repeater_parent', $parent['meta']['role']);
        $this->assertSame(3, $parent['meta']['childCount']);
        $this->assertSame('other', $parent['fieldFamily']);

        // Following items are the 3 leaves, each with status=available,
        // parentPublicId pointing at the parent, and row_index in source.
        for ($i = 1; $i <= 3; $i++) {
            $leaf = $items[$i];
            $this->assertSame('available', $leaf['status'], "Leaf $i status");
            $this->assertSame('repeater_leaf', $leaf['source']['role']);
            $this->assertSame('relationship', $leaf['source']['field_type']);
            $this->assertSame($i - 1, $leaf['source']['row_index']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $leaf['source']['expected_row_signature']);
            $this->assertSame('vertical:repeater_menus', $leaf['parentPublicId']);
            $this->assertSame('menus', $leaf['source']['repeater_field_name']);
        }

        // Row display labels heuristic: pick first non-empty string
        // subfield (menu_title in this fixture).
        $this->assertStringContainsString('Primary', $items[1]['label']);
        $this->assertStringContainsString('Footer', $items[2]['label']);
        $this->assertStringContainsString('Utility', $items[3]['label']);
    }

    public function test_repeater_with_empty_rows_emits_nothing(): void
    {
        $this->seedRepeaterChain('field_menu_posts', 'relationship', 'field_menus', 'menus');
        // Empty rows array — repeater exists but has no rows.
        $GLOBALS['vfe_test_acf_option_rows']['field_menus'] = [];

        $records = [
            [
                'id' => 'nav__menus__menu_posts',
                'label' => 'Menu posts',
                'field_name' => 'settings_nav_menus>menus>menu_posts',
                'field_key' => 'field_menu_posts',
                'field_type' => 'relationship',
                'unlocks_at' => 'R5.4',
                'category' => 'Layout',
                'group_title' => 'Nav menus',
                'owner_subtype' => 'advanced-settings',
                'client_priority' => 'nice',
                'notes' => '',
            ],
        ];

        $provider = $this->makeProviderWithRecords($records);
        $items = $provider->getControls();

        // Empty repeater → the mapped record's own resolveStatus fallback
        // returns 'unsupported' and it surfaces as-is (R5.6 behavior
        // preserved).
        $this->assertCount(1, $items);
        $this->assertSame('unsupported', $items[0]['status']);
        $this->assertArrayNotHasKey('role', $items[0]['meta'] ?? []);
    }

    public function test_repeater_parent_is_deduped_across_sibling_subfield_curation_entries(): void
    {
        // Two curated subfields under the same repeater: menu_posts +
        // menu_title. The parent record should appear ONCE, not twice.
        $this->seedRepeaterChain('field_menu_posts', 'relationship', 'field_menus', 'menus');
        // Also seed the second subfield with the same repeater parent.
        $GLOBALS['vfe_test_acf_fields']['field_menu_title'] = [
            'key' => 'field_menu_title',
            'name' => 'menu_title',
            'type' => 'text',
            'parent' => 'field_menus',
        ];
        $GLOBALS['vfe_test_acf_option_rows']['field_menus'] = [
            ['menu_title' => 'Primary', 'menu_posts' => [1]],
            ['menu_title' => 'Footer', 'menu_posts' => [2]],
        ];

        $records = [
            [
                'id' => 'nav__menus__menu_posts',
                'label' => 'Menu posts',
                'field_name' => 'settings_nav_menus>menus>menu_posts',
                'field_key' => 'field_menu_posts',
                'field_type' => 'relationship',
                'unlocks_at' => 'R5.4',
                'category' => 'Layout',
                'group_title' => 'Nav menus',
                'owner_subtype' => 'advanced-settings',
                'client_priority' => 'nice',
                'notes' => '',
            ],
            [
                'id' => 'nav__menus__menu_title',
                'label' => 'Menu title',
                'field_name' => 'settings_nav_menus>menus>menu_title',
                'field_key' => 'field_menu_title',
                'field_type' => 'text',
                'unlocks_at' => 'R5.1',
                'category' => 'Layout',
                'group_title' => 'Nav menus',
                'owner_subtype' => 'advanced-settings',
                'client_priority' => 'nice',
                'notes' => '',
            ],
        ];

        $provider = $this->makeProviderWithRecords($records);
        $items = $provider->getControls();

        // Expected: 1 parent + 2 rows × 2 subfields = 5 records total.
        $this->assertCount(5, $items);
        $parents = array_values(array_filter($items, function ($r) {
            return isset($r['meta']['role']) && $r['meta']['role'] === 'repeater_parent';
        }));
        $this->assertCount(1, $parents);
        $this->assertSame('repeater_menus', $parents[0]['id']);
    }

    public function test_repeater_leaf_source_carries_signature_matching_row_data(): void
    {
        $this->seedRepeaterChain('field_menu_title', 'text', 'field_menus', 'menus');
        $rows = [
            ['menu_title' => 'Primary'],
            ['menu_title' => 'Footer'],
        ];
        $GLOBALS['vfe_test_acf_option_rows']['field_menus'] = $rows;

        $records = [
            [
                'id' => 'nav__menus__menu_title',
                'label' => 'Menu title',
                'field_name' => 'settings_nav_menus>menus>menu_title',
                'field_key' => 'field_menu_title',
                'field_type' => 'text',
                'unlocks_at' => 'R5.1',
                'category' => 'Layout',
                'group_title' => 'Nav menus',
                'owner_subtype' => 'advanced-settings',
                'client_priority' => 'nice',
                'notes' => '',
            ],
        ];

        $provider = $this->makeProviderWithRecords($records);
        $items = $provider->getControls();

        $this->assertCount(3, $items);
        $leaf0 = $items[1];
        $leaf1 = $items[2];

        // Signatures should match what the resolver would recompute at
        // save time — this is what enables the concurrent-reorder guard.
        $expected_sig0 = \Dbvc\VisualEditor\Registry\Providers\SharedGlobalsControlProvider::buildRepeaterRowSignature($rows[0]);
        $expected_sig1 = \Dbvc\VisualEditor\Registry\Providers\SharedGlobalsControlProvider::buildRepeaterRowSignature($rows[1]);
        $this->assertSame($expected_sig0, $leaf0['source']['expected_row_signature']);
        $this->assertSame($expected_sig1, $leaf1['source']['expected_row_signature']);
        $this->assertNotSame($leaf0['source']['expected_row_signature'], $leaf1['source']['expected_row_signature']);
    }

    public function test_non_repeater_nested_record_falls_through_unchanged(): void
    {
        // Group-only ancestry → NO expansion. The mapped record surfaces
        // as-is (this is the pre-R5.7-a behavior for every currently
        // Available nested record).
        $this->seedGroupOnlyChain('field_brand_primary_logo', 'image');

        $records = [
            [
                'id' => 'brand__identity__primary_logo',
                'label' => 'Primary logo',
                'field_name' => 'brand>identity>primary_logo',
                'field_key' => 'field_brand_primary_logo',
                'field_type' => 'image',
                'unlocks_at' => 'R5.3',
                'category' => 'Brand',
                'group_title' => 'Identity',
                'owner_subtype' => 'brand-settings',
                'client_priority' => 'must',
                'notes' => '',
            ],
        ];

        $provider = $this->makeProviderWithRecords($records);
        $items = $provider->getControls();

        $this->assertCount(1, $items);
        $this->assertSame('available', $items[0]['status']);
        $this->assertArrayNotHasKey('role', $items[0]['meta'] ?? []);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function makeProviderWithRecords(array $records): \VF_Vertical_Control_Provider
    {
        $path = tempnam(sys_get_temp_dir(), 'vf_r51b_');
        file_put_contents(
            $path,
            (string) wp_json_encode(['records' => $records])
        );
        return new \VF_Vertical_Control_Provider(
            static function () use ($path) {
                return $path;
            }
        );
    }

    /**
     * Seed the ACF stub with a two-hop group-only chain terminating at
     * a `group_XXX` boundary. Leaves `parentChainIsGroupOnly` to walk
     * successfully. Appends to `$GLOBALS['vfe_test_acf_fields']` so a
     * single test can seed several leaves side-by-side if it wants.
     *
     * Chain shape:
     *   {$leaf_key} ({$leaf_type}, parent=field_r56_group_parent)
     *   field_r56_group_parent (group, parent=group_r56_owner)
     */
    private function seedGroupOnlyChain(string $leaf_key, string $leaf_type): void
    {
        if (! isset($GLOBALS['vfe_test_acf_fields']) || ! is_array($GLOBALS['vfe_test_acf_fields'])) {
            $GLOBALS['vfe_test_acf_fields'] = [];
        }
        $GLOBALS['vfe_test_acf_fields'][$leaf_key] = [
            'key' => $leaf_key,
            'name' => 'leaf',
            'type' => $leaf_type,
            'parent' => 'field_r56_group_parent',
        ];
        $GLOBALS['vfe_test_acf_fields']['field_r56_group_parent'] = [
            'key' => 'field_r56_group_parent',
            'name' => 'group_parent',
            'type' => 'group',
            'parent' => 'group_r56_owner',
        ];
    }

    /**
     * R5.7-a — Seed the ACF stub with a "leaf's immediate parent is a
     * repeater" chain that IS eligible for expansion (repeater above
     * the leaf, group above the repeater, group-XXX boundary above
     * that). `parentChainImmediateRepeaterInfo` returns non-null for
     * this shape.
     *
     * Chain shape:
     *   {$leaf_key} ({$leaf_type}, parent={$repeater_key})
     *   {$repeater_key} (repeater, parent=field_r57_group_parent, name={$repeater_name})
     *   field_r57_group_parent (group, parent=group_r57_owner)
     */
    private function seedRepeaterChain(string $leaf_key, string $leaf_type, string $repeater_key, string $repeater_name): void
    {
        if (! isset($GLOBALS['vfe_test_acf_fields']) || ! is_array($GLOBALS['vfe_test_acf_fields'])) {
            $GLOBALS['vfe_test_acf_fields'] = [];
        }
        $GLOBALS['vfe_test_acf_fields'][$leaf_key] = [
            'key' => $leaf_key,
            'name' => str_replace('field_', '', $leaf_key),
            'type' => $leaf_type,
            'parent' => $repeater_key,
        ];
        $GLOBALS['vfe_test_acf_fields'][$repeater_key] = [
            'key' => $repeater_key,
            'name' => $repeater_name,
            'type' => 'repeater',
            'parent' => 'field_r57_group_parent',
        ];
        $GLOBALS['vfe_test_acf_fields']['field_r57_group_parent'] = [
            'key' => 'field_r57_group_parent',
            'name' => 'group_parent',
            'type' => 'group',
            'parent' => 'group_r57_owner',
        ];
    }

    /**
     * Seed the ACF stub with a chain that puts a Repeater intermediate
     * between the leaf and a top-level Group. The gate walks up, hits
     * `type='repeater'`, and short-circuits to false — the record
     * therefore stays `unsupported`.
     *
     * Chain shape:
     *   {$leaf_key} ({$leaf_type}, parent=field_r56_repeater_parent)
     *   field_r56_repeater_parent (repeater, parent=field_r56_root_group)
     *   field_r56_root_group (group, parent=group_r56_owner)
     */
    private function seedRepeaterInChain(string $leaf_key, string $leaf_type): void
    {
        if (! isset($GLOBALS['vfe_test_acf_fields']) || ! is_array($GLOBALS['vfe_test_acf_fields'])) {
            $GLOBALS['vfe_test_acf_fields'] = [];
        }
        $GLOBALS['vfe_test_acf_fields'][$leaf_key] = [
            'key' => $leaf_key,
            'name' => 'leaf',
            'type' => $leaf_type,
            'parent' => 'field_r56_repeater_parent',
        ];
        $GLOBALS['vfe_test_acf_fields']['field_r56_repeater_parent'] = [
            'key' => 'field_r56_repeater_parent',
            'name' => 'repeater_parent',
            'type' => 'repeater',
            'parent' => 'field_r56_root_group',
        ];
        $GLOBALS['vfe_test_acf_fields']['field_r56_root_group'] = [
            'key' => 'field_r56_root_group',
            'name' => 'root_group',
            'type' => 'group',
            'parent' => 'group_r56_owner',
        ];
    }
}
