<?php

use Dbvc\VisualEditor\Permissions\CapabilityManager;
use Dbvc\VisualEditor\Registry\ControlRegistry;
use Dbvc\VisualEditor\Registry\Providers\SharedGlobalsControlProvider;
use Dbvc\VisualEditor\Registry\Providers\SharedGlobalsDescriptorFactory;
use Dbvc\VisualEditor\Registry\EditableDescriptor;
use Dbvc\VisualEditor\Resolvers\AcfColorPickerResolver;

/**
 * R5.2+color_picker — color-picker unlock for the Brand Control Center.
 *
 * Covers all four surfaces R5.2+color_picker touches:
 *   1. New AcfColorPickerResolver — supports color_picker field_type,
 *      validates hex/rgb(a) shapes, normalizes hex to lowercase 7-char.
 *   2. SharedGlobalsControlProvider — supportedFieldTypes widened by
 *      color_picker; buildColorSummary static helper accepts every
 *      recognized color shape and normalizes hex; buildValueSummary
 *      routes color_picker to it.
 *   3. SharedGlobalsDescriptorFactory — color_picker branch emits
 *      `shared_field` descriptor with resolver=acf_color_picker,
 *      ui.input=color (native `<input type="color">` in the panel).
 *   4. Provider integration — a color_picker field surfaces as
 *      status=available in the registry list.
 */
final class VisualEditorControlCenterR52ColorPickerTest extends WP_UnitTestCase
{
    private const COOKIE_NAME = 'dbvc_visual_editor_mode';
    private const TRANSIENT_PREFIX = 'dbvc_visual_editor_session_';

    /**
     * @var int
     */
    private $administrator_id = 0;

    /**
     * @var string
     */
    private $session_id = '';

    public function set_up(): void
    {
        parent::set_up();
        $this->administrator_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->administrator_id);
        $_COOKIE[self::COOKIE_NAME] = '1';
        $this->session_id = 'ves_' . strtolower(wp_generate_password(12, false, false));
        $this->seedSession($this->session_id, $this->administrator_id);
    }

    public function tear_down(): void
    {
        delete_transient(self::TRANSIENT_PREFIX . $this->session_id);
        unset($_COOKIE[self::COOKIE_NAME]);
        parent::tear_down();
    }

    // ---------------------------------------------------------------------
    // 1. AcfColorPickerResolver
    // ---------------------------------------------------------------------

    public function test_resolver_name_is_acf_color_picker(): void
    {
        $resolver = new AcfColorPickerResolver();
        $this->assertSame('acf_color_picker', $resolver->name());
    }

    public function test_resolver_supports_only_color_picker_field_type(): void
    {
        $resolver = new AcfColorPickerResolver();
        $this->assertTrue($resolver->supports($this->makeAcfDescriptor('color_picker')));
        $this->assertFalse($resolver->supports($this->makeAcfDescriptor('text')));
        $this->assertFalse($resolver->supports($this->makeAcfDescriptor('image')));
    }

    /**
     * @dataProvider recognizedColorStringsProvider
     */
    public function test_resolver_recognizes_valid_color_strings(string $input): void
    {
        $this->assertTrue(AcfColorPickerResolver::isRecognizedColorString($input));
    }

    public function recognizedColorStringsProvider(): array
    {
        return [
            '3-char hex' => ['#f60'],
            '6-char hex lower' => ['#ff6600'],
            '6-char hex upper' => ['#FF6600'],
            '8-char hex with alpha' => ['#ff6600cc'],
            'rgb()' => ['rgb(255, 102, 0)'],
            'rgba()' => ['rgba(255, 102, 0, 0.5)'],
        ];
    }

    /**
     * @dataProvider rejectedColorStringsProvider
     */
    public function test_resolver_rejects_bogus_color_strings(string $input): void
    {
        $this->assertFalse(AcfColorPickerResolver::isRecognizedColorString($input));
    }

    public function rejectedColorStringsProvider(): array
    {
        return [
            'no hash' => ['ff6600'],
            'javascript-scheme injection' => ['javascript:alert(1)'],
            'named color' => ['red'],
            'html-shaped' => ['<script>'],
            'partial hex' => ['#zz'],
        ];
    }

    public function test_resolver_sanitize_normalizes_hex_to_lowercase_six_char(): void
    {
        $resolver = new AcfColorPickerResolver();
        $descriptor = $this->makeAcfDescriptor('color_picker');
        $this->assertSame('#ff6600', $resolver->sanitize($descriptor, '#FF6600'));
        $this->assertSame('#ffaa00', $resolver->sanitize($descriptor, '#fa0')); // 3-char expands
        $this->assertSame('', $resolver->sanitize($descriptor, ''));
        $this->assertSame('', $resolver->sanitize($descriptor, null));
    }

    public function test_resolver_validate_accepts_empty_and_recognized_colors_rejects_junk(): void
    {
        $resolver = new AcfColorPickerResolver();
        $descriptor = $this->makeAcfDescriptor('color_picker');
        $this->assertTrue($resolver->validate($descriptor, '')['ok']);
        $this->assertTrue($resolver->validate($descriptor, null)['ok']);
        $this->assertTrue($resolver->validate($descriptor, '#ffaabb')['ok']);
        $this->assertFalse($resolver->validate($descriptor, 'not-a-color')['ok']);
        $this->assertFalse($resolver->validate($descriptor, ['array'])['ok']);
    }

    // ---------------------------------------------------------------------
    // 2. SharedGlobalsControlProvider — whitelist + buildColorSummary
    // ---------------------------------------------------------------------

    public function test_supported_field_types_includes_color_picker(): void
    {
        $this->assertContains('color_picker', SharedGlobalsControlProvider::supportedFieldTypes());
    }

    public function test_build_color_summary_returns_lowercase_hex_from_uppercase_input(): void
    {
        $summary = SharedGlobalsControlProvider::buildColorSummary('#FF6600');
        $this->assertSame(['family' => 'color', 'hex' => '#ff6600'], $summary);
    }

    public function test_build_color_summary_expands_short_hex(): void
    {
        $summary = SharedGlobalsControlProvider::buildColorSummary('#f60');
        // 3-char hex expands each digit to its 2-char equivalent:
        // f → ff, 6 → 66, 0 → 00.
        $this->assertSame(['family' => 'color', 'hex' => '#ff6600'], $summary);
    }

    public function test_build_color_summary_preserves_rgba(): void
    {
        $summary = SharedGlobalsControlProvider::buildColorSummary('rgba(255, 102, 0, 0.5)');
        $this->assertSame('color', $summary['family']);
        $this->assertStringContainsString('rgba', $summary['hex']);
    }

    public function test_build_color_summary_null_for_unrecognized_input(): void
    {
        $this->assertNull(SharedGlobalsControlProvider::buildColorSummary('red'));
        $this->assertNull(SharedGlobalsControlProvider::buildColorSummary(''));
        $this->assertNull(SharedGlobalsControlProvider::buildColorSummary(null));
        $this->assertNull(SharedGlobalsControlProvider::buildColorSummary(['ff' => '00']));
    }

    // ---------------------------------------------------------------------
    // 3. SharedGlobalsDescriptorFactory — color_picker branch
    // ---------------------------------------------------------------------

    public function test_factory_builds_color_descriptor_for_color_picker_field(): void
    {
        $factory = new SharedGlobalsDescriptorFactory();
        $descriptor = $factory->build('ves_test', [], [
            'name' => 'brand_accent',
            'key' => 'field_brand_accent',
            'type' => 'color_picker',
            'label' => 'Brand accent',
            'parent' => '',
        ]);
        $this->assertSame('acf_field', $descriptor->source['type']);
        $this->assertSame('color_picker', $descriptor->source['field_type']);
        $this->assertSame('color', $descriptor->ui['input']);
        $this->assertSame('acf_color_picker', $descriptor->resolver['name']);
        $this->assertSame('shared_field', $descriptor->mutation['contract']);
        $this->assertSame('scalar', $descriptor->mutation['kind']);
    }

    // ---------------------------------------------------------------------
    // 4. Provider integration
    // ---------------------------------------------------------------------

    public function test_provider_emits_available_color_picker_record(): void
    {
        $provider = $this->makeSharedGlobalsProvider(
            ['brand_accent'],
            [
                'brand_accent' => [
                    'name' => 'brand_accent',
                    'key' => 'field_brand_accent',
                    'type' => 'color_picker',
                    'label' => 'Brand accent',
                    'parent' => '',
                ],
            ]
        );
        $registry = new ControlRegistry();
        $registry->registerProvider($provider);
        $items = $registry->listControls();

        $this->assertCount(1, $items);
        $this->assertSame('available', $items[0]['status']);
        // ControlRecord whitelist doesn't have a 'color' bucket, so the
        // family falls into 'other'. This is the same shape R5.2 families
        // took.
        $this->assertSame('other', $items[0]['fieldFamily']);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function makeAcfDescriptor(string $field_type): EditableDescriptor
    {
        return new EditableDescriptor(
            've_test_' . $field_type,
            'editable',
            'shared_entity',
            ['type' => 'option', 'id' => 0, 'subtype' => 'acf_options', 'acf_object_id' => 'option'],
            [],
            [
                'type' => 'acf_field',
                'field_name' => 'sample',
                'field_key' => 'field_sample',
                'field_selector' => 'sample',
                'field_selector_raw' => 'sample',
                'field_type' => $field_type,
            ],
            [],
            []
        );
    }

    private function makeSharedGlobalsProvider(
        array $configured_names,
        array $field_objects,
        $option_value_resolver = null
    ): SharedGlobalsControlProvider {
        return new SharedGlobalsControlProvider(
            new CapabilityManager(),
            static function () use ($configured_names) {
                return $configured_names;
            },
            static function ($name) use ($field_objects) {
                return array_key_exists($name, $field_objects) ? $field_objects[$name] : null;
            },
            $option_value_resolver
        );
    }

    private function seedSession(string $session_id, int $user_id): void
    {
        set_transient(
            self::TRANSIENT_PREFIX . $session_id,
            [
                'user_id' => $user_id,
                'blog_id' => get_current_blog_id(),
                'started_at' => time(),
                'expires_at' => time() + 60,
                'descriptors' => [],
                'descriptor_hashes' => [],
                'page_context' => [],
            ],
            60
        );
    }
}
