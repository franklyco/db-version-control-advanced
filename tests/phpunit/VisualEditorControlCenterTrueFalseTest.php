<?php

use Dbvc\VisualEditor\Permissions\CapabilityManager;
use Dbvc\VisualEditor\Registry\ControlRegistry;
use Dbvc\VisualEditor\Registry\EditableDescriptor;
use Dbvc\VisualEditor\Registry\Providers\SharedGlobalsControlProvider;
use Dbvc\VisualEditor\Registry\Providers\SharedGlobalsDescriptorFactory;
use Dbvc\VisualEditor\Resolvers\AcfTrueFalseResolver;

/**
 * true_false — boolean unlock for the Brand Control Center.
 *
 * Covers all four surfaces the true_false slice touches:
 *   1. New AcfTrueFalseResolver — supports true_false field_type,
 *      validates boolean-shaped inputs, sanitizes to 1/0 int, exposes
 *      public static `coerceToBool` reused by the summary builder.
 *   2. SharedGlobalsControlProvider — supportedFieldTypes widened;
 *      new public static `buildBooleanSummary` returns
 *      {family:'boolean', value:bool, label:'On'|'Off'}.
 *   3. SharedGlobalsDescriptorFactory — true_false branch emits
 *      resolver='acf_true_false', ui.input='true_false',
 *      contract='shared_field'.
 *   4. Provider integration — a true_false field surfaces as available.
 */
final class VisualEditorControlCenterTrueFalseTest extends WP_UnitTestCase
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
    // 1. AcfTrueFalseResolver
    // ---------------------------------------------------------------------

    public function test_resolver_name_is_acf_true_false(): void
    {
        $this->assertSame('acf_true_false', (new AcfTrueFalseResolver())->name());
    }

    public function test_resolver_supports_only_true_false_field_type(): void
    {
        $resolver = new AcfTrueFalseResolver();
        $this->assertTrue($resolver->supports($this->makeAcfDescriptor('true_false')));
        $this->assertFalse($resolver->supports($this->makeAcfDescriptor('text')));
        $this->assertFalse($resolver->supports($this->makeAcfDescriptor('checkbox')));
    }

    /**
     * @dataProvider truthyInputsProvider
     */
    public function test_coerce_to_bool_recognizes_truthy_shapes($input): void
    {
        $this->assertTrue(AcfTrueFalseResolver::coerceToBool($input));
    }

    public function truthyInputsProvider(): array
    {
        return [
            'bool true' => [true],
            'int 1' => [1],
            'string 1' => ['1'],
            'string true' => ['true'],
            'string TRUE' => ['TRUE'],
            'string yes' => ['yes'],
            'string on' => ['on'],
        ];
    }

    /**
     * @dataProvider falsyInputsProvider
     */
    public function test_coerce_to_bool_recognizes_falsy_shapes($input): void
    {
        $this->assertFalse(AcfTrueFalseResolver::coerceToBool($input));
    }

    public function falsyInputsProvider(): array
    {
        return [
            'bool false' => [false],
            'int 0' => [0],
            'string 0' => ['0'],
            'string false' => ['false'],
            'string off' => ['off'],
            'string no' => ['no'],
            'empty string' => [''],
            'null' => [null],
        ];
    }

    public function test_resolver_sanitize_normalizes_to_one_or_zero(): void
    {
        $resolver = new AcfTrueFalseResolver();
        $descriptor = $this->makeAcfDescriptor('true_false');
        $this->assertSame(1, $resolver->sanitize($descriptor, true));
        $this->assertSame(1, $resolver->sanitize($descriptor, '1'));
        $this->assertSame(0, $resolver->sanitize($descriptor, false));
        $this->assertSame(0, $resolver->sanitize($descriptor, ''));
        $this->assertSame(0, $resolver->sanitize($descriptor, null));
    }

    public function test_resolver_validate_accepts_boolean_shapes_rejects_junk(): void
    {
        $resolver = new AcfTrueFalseResolver();
        $descriptor = $this->makeAcfDescriptor('true_false');
        $this->assertTrue($resolver->validate($descriptor, true)['ok']);
        $this->assertTrue($resolver->validate($descriptor, false)['ok']);
        $this->assertTrue($resolver->validate($descriptor, '1')['ok']);
        $this->assertTrue($resolver->validate($descriptor, 'on')['ok']);
        $this->assertTrue($resolver->validate($descriptor, '')['ok']);
        $this->assertFalse($resolver->validate($descriptor, 'maybe')['ok']);
        $this->assertFalse($resolver->validate($descriptor, ['array'])['ok']);
    }

    // ---------------------------------------------------------------------
    // 2. SharedGlobalsControlProvider — whitelist + buildBooleanSummary
    // ---------------------------------------------------------------------

    public function test_supported_field_types_includes_true_false(): void
    {
        $this->assertContains('true_false', SharedGlobalsControlProvider::supportedFieldTypes());
    }

    public function test_build_boolean_summary_true(): void
    {
        $summary = SharedGlobalsControlProvider::buildBooleanSummary(1);
        $this->assertSame('boolean', $summary['family']);
        $this->assertTrue($summary['value']);
        $this->assertSame('On', $summary['label']);
    }

    public function test_build_boolean_summary_false(): void
    {
        $summary = SharedGlobalsControlProvider::buildBooleanSummary(0);
        $this->assertSame('boolean', $summary['family']);
        $this->assertFalse($summary['value']);
        $this->assertSame('Off', $summary['label']);
    }

    public function test_build_boolean_summary_never_returns_null(): void
    {
        // Unlike other family summaries, boolean always has a summary —
        // an empty ACF value coerces to false → Off label. This matches
        // the mockup rule that a boolean chip is always visible.
        $summary = SharedGlobalsControlProvider::buildBooleanSummary(null);
        $this->assertFalse($summary['value']);
        $this->assertSame('Off', $summary['label']);
    }

    // ---------------------------------------------------------------------
    // 3. SharedGlobalsDescriptorFactory — true_false branch
    // ---------------------------------------------------------------------

    public function test_factory_builds_boolean_descriptor_for_true_false_field(): void
    {
        $factory = new SharedGlobalsDescriptorFactory();
        $descriptor = $factory->build('ves_test', [], [
            'name' => 'feature_enabled',
            'key' => 'field_feature_enabled',
            'type' => 'true_false',
            'label' => 'Feature enabled',
            'parent' => '',
        ]);
        $this->assertSame('acf_field', $descriptor->source['type']);
        $this->assertSame('true_false', $descriptor->source['field_type']);
        $this->assertSame('true_false', $descriptor->ui['input']);
        $this->assertSame('acf_true_false', $descriptor->resolver['name']);
        $this->assertSame('shared_field', $descriptor->mutation['contract']);
        $this->assertSame('scalar', $descriptor->mutation['kind']);
    }

    // ---------------------------------------------------------------------
    // 4. Provider integration
    // ---------------------------------------------------------------------

    public function test_provider_emits_available_true_false_record(): void
    {
        $provider = $this->makeSharedGlobalsProvider(
            ['feature_enabled'],
            [
                'feature_enabled' => [
                    'name' => 'feature_enabled',
                    'key' => 'field_feature_enabled',
                    'type' => 'true_false',
                    'label' => 'Feature enabled',
                    'parent' => '',
                ],
            ]
        );
        $registry = new ControlRegistry();
        $registry->registerProvider($provider);
        $items = $registry->listControls();

        $this->assertCount(1, $items);
        $this->assertSame('available', $items[0]['status']);
        // ControlRecord whitelist has no boolean bucket — falls into 'other'.
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
