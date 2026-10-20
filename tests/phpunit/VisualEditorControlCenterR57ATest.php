<?php

use Dbvc\VisualEditor\Registry\Providers\SharedGlobalsControlProvider;
use Dbvc\VisualEditor\Registry\Providers\SharedGlobalsDescriptorFactory;

/**
 * R5.7-a — DBVC-side backend for repeater-subfield editing.
 *
 * Covers the two primitives R5.7-a adds:
 *
 *   1. `SharedGlobalsControlProvider::buildRepeaterRowSignature($row)` —
 *      deterministic sha1 of a row's data, used at descriptor-mint time
 *      and re-verified at save time.
 *   2. `SharedGlobalsDescriptorFactory::buildRepeaterSubfieldDescriptor()`
 *      — mints the `acf_repeater_subfield` /
 *      `acf_collection_field`+`container_type=repeater` descriptor the
 *      resolver's `writeRepeaterSubfieldValue` path consumes.
 *
 * The resolver-side signature verification
 * (`AbstractAcfResolver::verifyExpectedRowSignature` /
 * `writeRepeaterSubfieldValue` guard) is exercised indirectly here via
 * signature equality checks; the full write pipeline needs a real ACF
 * environment and is covered by the live-site R5.7-c QA pass.
 */
final class VisualEditorControlCenterR57ATest extends WP_UnitTestCase
{
    // ---------------------------------------------------------------------
    // buildRepeaterRowSignature — deterministic, order-insensitive
    // ---------------------------------------------------------------------

    public function test_signature_is_deterministic_across_calls(): void
    {
        $row = ['menu_title' => 'Primary', 'menu_posts' => [1, 2, 3]];
        $a = SharedGlobalsControlProvider::buildRepeaterRowSignature($row);
        $b = SharedGlobalsControlProvider::buildRepeaterRowSignature($row);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $a);
        $this->assertSame($a, $b);
    }

    public function test_signature_is_insensitive_to_key_order(): void
    {
        $a = SharedGlobalsControlProvider::buildRepeaterRowSignature([
            'menu_title' => 'Primary',
            'menu_posts' => [1, 2, 3],
        ]);
        $b = SharedGlobalsControlProvider::buildRepeaterRowSignature([
            'menu_posts' => [1, 2, 3],
            'menu_title' => 'Primary',
        ]);
        $this->assertSame($a, $b);
    }

    public function test_signature_is_sensitive_to_value_changes(): void
    {
        $a = SharedGlobalsControlProvider::buildRepeaterRowSignature(['menu_title' => 'Primary']);
        $b = SharedGlobalsControlProvider::buildRepeaterRowSignature(['menu_title' => 'Footer']);
        $this->assertNotSame($a, $b);
    }

    public function test_signature_strips_acf_row_transient_keys(): void
    {
        $a = SharedGlobalsControlProvider::buildRepeaterRowSignature([
            'menu_title' => 'Primary',
        ]);
        $b = SharedGlobalsControlProvider::buildRepeaterRowSignature([
            'menu_title' => 'Primary',
            'acf_row_5f42' => 'row_5f42b1',
        ]);
        $this->assertSame($a, $b);
    }

    public function test_signature_empty_string_for_non_array(): void
    {
        $this->assertSame('', SharedGlobalsControlProvider::buildRepeaterRowSignature('not_array'));
        $this->assertSame('', SharedGlobalsControlProvider::buildRepeaterRowSignature(null));
        $this->assertSame('', SharedGlobalsControlProvider::buildRepeaterRowSignature(42));
    }

    public function test_signature_recurses_into_nested_arrays_deterministically(): void
    {
        $a = SharedGlobalsControlProvider::buildRepeaterRowSignature([
            'settings' => ['visible' => true, 'label' => 'Show'],
            'items' => [10, 20],
        ]);
        $b = SharedGlobalsControlProvider::buildRepeaterRowSignature([
            'items' => [10, 20],
            'settings' => ['label' => 'Show', 'visible' => true],
        ]);
        $this->assertSame($a, $b);
    }

    // ---------------------------------------------------------------------
    // buildRepeaterSubfieldDescriptor — happy path shape
    // ---------------------------------------------------------------------

    public function test_text_leaf_descriptor_has_repeater_subfield_source(): void
    {
        $factory = new SharedGlobalsDescriptorFactory();
        $parent_repeater = $this->makeRepeaterField('menus', 'field_menus');
        $subfield = $this->makeSubfield('menu_title', 'field_menu_title', 'text');
        $signature = SharedGlobalsControlProvider::buildRepeaterRowSignature(['menu_title' => 'Primary']);

        $descriptor = $factory->buildRepeaterSubfieldDescriptor(
            'sess_test',
            [],
            $parent_repeater,
            $subfield,
            2,
            $signature
        );

        $this->assertNotNull($descriptor);
        $this->assertSame('acf_repeater_subfield', $descriptor->source['type']);
        $this->assertSame('repeater', $descriptor->source['container_type']);
        $this->assertSame('menus', $descriptor->source['parent_field_name']);
        $this->assertSame('field_menus', $descriptor->source['parent_field_key']);
        $this->assertSame('field_menus', $descriptor->source['parent_field_selector']);
        $this->assertSame(2, $descriptor->source['row_index']);
        $this->assertSame($signature, $descriptor->source['expected_row_signature']);
        $this->assertSame('menu_title', $descriptor->source['field_name']);
        $this->assertSame('field_menu_title', $descriptor->source['field_key']);
        $this->assertSame('text', $descriptor->source['field_type']);
        $this->assertSame('acf_text', $descriptor->resolver['name']);
        $this->assertSame('shared_field', $descriptor->mutation['contract']);
        $this->assertSame('scalar', $descriptor->mutation['kind']);
        $this->assertSame('field', $descriptor->render['context']);
        $this->assertSame('option', $descriptor->entity['type']);
        $this->assertSame('option', $descriptor->entity['acf_object_id']);
    }

    public function test_relationship_leaf_descriptor_has_collection_source_and_query_context(): void
    {
        $factory = new SharedGlobalsDescriptorFactory();
        $parent_repeater = $this->makeRepeaterField('menus', 'field_menus');
        $subfield = $this->makeSubfield('menu_posts', 'field_menu_posts', 'relationship');
        $subfield['post_type'] = ['post'];
        $signature = SharedGlobalsControlProvider::buildRepeaterRowSignature(['menu_posts' => [1, 2]]);

        $descriptor = $factory->buildRepeaterSubfieldDescriptor(
            'sess_test',
            [],
            $parent_repeater,
            $subfield,
            0,
            $signature
        );

        $this->assertNotNull($descriptor);
        // Reference leaves use acf_collection_field so AcfReferenceCollectionResolver::supports matches
        $this->assertSame('acf_collection_field', $descriptor->source['type']);
        $this->assertSame('repeater', $descriptor->source['container_type']);
        $this->assertSame('query_collection', $descriptor->render['context']);
        $this->assertSame('shared_relationship_collection', $descriptor->mutation['contract']);
        $this->assertSame('collection', $descriptor->mutation['kind']);
        $this->assertSame('acf_reference_collection', $descriptor->resolver['name']);
        $this->assertSame(['post'], $descriptor->source['reference_post_types']);
        $this->assertTrue($descriptor->source['reference_multiple']);
    }

    public function test_post_object_leaf_uses_post_object_contract(): void
    {
        $factory = new SharedGlobalsDescriptorFactory();
        $parent_repeater = $this->makeRepeaterField('menus', 'field_menus');
        $subfield = $this->makeSubfield('featured_page', 'field_featured_page', 'post_object');

        $descriptor = $factory->buildRepeaterSubfieldDescriptor(
            'sess_test',
            [],
            $parent_repeater,
            $subfield,
            0,
            SharedGlobalsControlProvider::buildRepeaterRowSignature(['featured_page' => 42])
        );

        $this->assertNotNull($descriptor);
        $this->assertSame('shared_post_object_collection', $descriptor->mutation['contract']);
    }

    public function test_choice_leaf_projects_ui_options_from_choices(): void
    {
        $factory = new SharedGlobalsDescriptorFactory();
        $parent_repeater = $this->makeRepeaterField('menus', 'field_menus');
        $subfield = $this->makeSubfield('menu_type', 'field_menu_type', 'select');
        $subfield['choices'] = ['primary' => 'Primary', 'footer' => 'Footer'];

        $descriptor = $factory->buildRepeaterSubfieldDescriptor(
            'sess_test',
            [],
            $parent_repeater,
            $subfield,
            0,
            SharedGlobalsControlProvider::buildRepeaterRowSignature(['menu_type' => 'primary'])
        );

        $this->assertNotNull($descriptor);
        $this->assertSame('acf_choice', $descriptor->resolver['name']);
        $this->assertSame('select', $descriptor->ui['input']);
        $this->assertArrayHasKey('options', $descriptor->ui);
        $this->assertCount(2, $descriptor->ui['options']);
        $this->assertSame('primary', $descriptor->ui['options'][0]['value']);
        $this->assertSame('Primary', $descriptor->ui['options'][0]['label']);
    }

    public function test_multi_checkbox_leaf_uses_checkbox_group_input(): void
    {
        $factory = new SharedGlobalsDescriptorFactory();
        $parent_repeater = $this->makeRepeaterField('menus', 'field_menus');
        $subfield = $this->makeSubfield('flags', 'field_flags', 'checkbox');
        $subfield['choices'] = ['a' => 'A', 'b' => 'B'];

        $descriptor = $factory->buildRepeaterSubfieldDescriptor(
            'sess_test',
            [],
            $parent_repeater,
            $subfield,
            0,
            SharedGlobalsControlProvider::buildRepeaterRowSignature(['flags' => ['a']])
        );

        $this->assertNotNull($descriptor);
        $this->assertSame('checkbox_group', $descriptor->ui['input']);
    }

    public function test_image_leaf_uses_media_reference_input(): void
    {
        $factory = new SharedGlobalsDescriptorFactory();
        $parent_repeater = $this->makeRepeaterField('slides', 'field_slides');
        $subfield = $this->makeSubfield('image', 'field_slide_image', 'image');

        $descriptor = $factory->buildRepeaterSubfieldDescriptor(
            'sess_test',
            [],
            $parent_repeater,
            $subfield,
            0,
            SharedGlobalsControlProvider::buildRepeaterRowSignature(['image' => 42])
        );

        $this->assertNotNull($descriptor);
        $this->assertSame('acf_image', $descriptor->resolver['name']);
        $this->assertSame('media_reference', $descriptor->ui['input']);
    }

    public function test_true_false_leaf_uses_boolean_resolver(): void
    {
        $factory = new SharedGlobalsDescriptorFactory();
        $parent_repeater = $this->makeRepeaterField('menus', 'field_menus');
        $subfield = $this->makeSubfield('show_in_footer', 'field_show', 'true_false');

        $descriptor = $factory->buildRepeaterSubfieldDescriptor(
            'sess_test',
            [],
            $parent_repeater,
            $subfield,
            0,
            SharedGlobalsControlProvider::buildRepeaterRowSignature(['show_in_footer' => true])
        );

        $this->assertNotNull($descriptor);
        $this->assertSame('acf_true_false', $descriptor->resolver['name']);
        $this->assertSame('true_false', $descriptor->ui['input']);
    }

    public function test_color_picker_leaf_uses_color_input(): void
    {
        $factory = new SharedGlobalsDescriptorFactory();
        $parent_repeater = $this->makeRepeaterField('palettes', 'field_palettes');
        $subfield = $this->makeSubfield('accent', 'field_accent', 'color_picker');

        $descriptor = $factory->buildRepeaterSubfieldDescriptor(
            'sess_test',
            [],
            $parent_repeater,
            $subfield,
            0,
            SharedGlobalsControlProvider::buildRepeaterRowSignature(['accent' => '#ff6600'])
        );

        $this->assertNotNull($descriptor);
        $this->assertSame('acf_color_picker', $descriptor->resolver['name']);
        $this->assertSame('color', $descriptor->ui['input']);
    }

    // ---------------------------------------------------------------------
    // buildRepeaterSubfieldDescriptor — null cases
    // ---------------------------------------------------------------------

    public function test_returns_null_for_unsupported_field_type(): void
    {
        $factory = new SharedGlobalsDescriptorFactory();
        $parent_repeater = $this->makeRepeaterField('menus', 'field_menus');
        $subfield = $this->makeSubfield('timestamp', 'field_ts', 'date_time_picker');

        $result = $factory->buildRepeaterSubfieldDescriptor(
            'sess_test',
            [],
            $parent_repeater,
            $subfield,
            0,
            SharedGlobalsControlProvider::buildRepeaterRowSignature([])
        );

        $this->assertNull($result);
    }

    public function test_returns_null_for_missing_parent_identifiers(): void
    {
        $factory = new SharedGlobalsDescriptorFactory();
        $parent_repeater = ['name' => '', 'key' => '', 'type' => 'repeater'];
        $subfield = $this->makeSubfield('menu_title', 'field_menu_title', 'text');

        $result = $factory->buildRepeaterSubfieldDescriptor(
            'sess_test',
            [],
            $parent_repeater,
            $subfield,
            0,
            SharedGlobalsControlProvider::buildRepeaterRowSignature([])
        );

        $this->assertNull($result);
    }

    public function test_returns_null_for_missing_leaf_identifiers(): void
    {
        $factory = new SharedGlobalsDescriptorFactory();
        $parent_repeater = $this->makeRepeaterField('menus', 'field_menus');
        $subfield = ['name' => '', 'key' => '', 'type' => 'text'];

        $result = $factory->buildRepeaterSubfieldDescriptor(
            'sess_test',
            [],
            $parent_repeater,
            $subfield,
            0,
            SharedGlobalsControlProvider::buildRepeaterRowSignature([])
        );

        $this->assertNull($result);
    }

    public function test_invalid_signature_shape_is_normalized_to_empty(): void
    {
        // Signature that doesn't match /^[a-f0-9]{40}$/ is silently dropped;
        // the resolver then skips the guard (backwards-compat path).
        $factory = new SharedGlobalsDescriptorFactory();
        $parent_repeater = $this->makeRepeaterField('menus', 'field_menus');
        $subfield = $this->makeSubfield('menu_title', 'field_menu_title', 'text');

        $descriptor = $factory->buildRepeaterSubfieldDescriptor(
            'sess_test',
            [],
            $parent_repeater,
            $subfield,
            0,
            'not-a-real-signature'
        );

        $this->assertNotNull($descriptor);
        $this->assertSame('', $descriptor->source['expected_row_signature']);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @param string $name
     * @param string $key
     * @return array<string, mixed>
     */
    private function makeRepeaterField($name, $key)
    {
        return [
            'name' => $name,
            'key' => $key,
            'type' => 'repeater',
            'label' => ucfirst($name),
            'parent' => 'group_test',
            '_option_pages' => ['acf-options-vertical'],
        ];
    }

    /**
     * @param string $name
     * @param string $key
     * @param string $type
     * @return array<string, mixed>
     */
    private function makeSubfield($name, $key, $type)
    {
        return [
            'name' => $name,
            'key' => $key,
            'type' => $type,
            'label' => ucfirst(str_replace('_', ' ', $name)),
            'parent' => 'field_menus',
        ];
    }
}
