<?php

use Dbvc\VisualEditor\Permissions\CapabilityManager;
use Dbvc\VisualEditor\Registry\ControlRegistry;
use Dbvc\VisualEditor\Registry\EditableDescriptor;
use Dbvc\VisualEditor\Registry\Providers\SharedGlobalsControlProvider;
use Dbvc\VisualEditor\Registry\Providers\SharedGlobalsDescriptorFactory;
use Dbvc\VisualEditor\Resolvers\AcfDatePickerResolver;

/**
 * R5.5 — date_picker unlock for the Brand Control Center.
 *
 * Covers all four surfaces the R5.5 slice touches:
 *   1. New AcfDatePickerResolver — supports date_picker field_type,
 *      validates ISO-8601 date shapes (rejects junk), sanitizes to
 *      YYYY-MM-DD, exposes public static `normalizeIsoDate` +
 *      `isRecognizedIsoDate` reused by the summary builder.
 *   2. SharedGlobalsControlProvider — supportedFieldTypes widened;
 *      new public static `buildDateSummary` returns
 *      {family:'date', iso, label} for valid, null for invalid/empty.
 *   3. SharedGlobalsDescriptorFactory — date_picker branch emits
 *      resolver='acf_date_picker', ui.input='date',
 *      contract='shared_field'.
 *   4. Provider integration — a date_picker field surfaces as available.
 */
final class VisualEditorControlCenterR55DatePickerTest extends WP_UnitTestCase
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
    // 1. AcfDatePickerResolver
    // ---------------------------------------------------------------------

    public function test_resolver_name_is_acf_date_picker(): void
    {
        $this->assertSame('acf_date_picker', (new AcfDatePickerResolver())->name());
    }

    public function test_resolver_supports_only_date_picker_field_type(): void
    {
        $resolver = new AcfDatePickerResolver();
        $this->assertTrue($resolver->supports($this->makeAcfDescriptor('date_picker')));
        $this->assertFalse($resolver->supports($this->makeAcfDescriptor('text')));
        $this->assertFalse($resolver->supports($this->makeAcfDescriptor('date_time_picker')));
    }

    /**
     * @dataProvider validIsoDatesProvider
     */
    public function test_is_recognized_iso_date_accepts_valid_dates(string $input): void
    {
        $this->assertTrue(AcfDatePickerResolver::isRecognizedIsoDate($input));
    }

    public function validIsoDatesProvider(): array
    {
        return [
            'today-ish' => ['2026-09-02'],
            'first of year' => ['2020-01-01'],
            'leap-year Feb 29' => ['2024-02-29'],
            'end of year' => ['2099-12-31'],
        ];
    }

    /**
     * @dataProvider invalidDateShapesProvider
     */
    public function test_is_recognized_iso_date_rejects_junk($input): void
    {
        $this->assertFalse(AcfDatePickerResolver::isRecognizedIsoDate($input));
    }

    public function invalidDateShapesProvider(): array
    {
        return [
            'US slashes' => ['09/02/2026'],
            'European dot' => ['02.09.2026'],
            'ISO with time' => ['2026-09-02T00:00:00'],
            'partial year-month' => ['2026-09'],
            'two-digit year' => ['26-09-02'],
            'Feb 30 non-leap' => ['2025-02-30'],
            'month 13' => ['2026-13-01'],
            'day 32' => ['2026-01-32'],
            'empty' => [''],
            'null' => [null],
            'integer' => [20260902],
            'array' => [['2026', '09', '02']],
        ];
    }

    public function test_resolver_sanitize_normalizes_iso_dates(): void
    {
        $resolver = new AcfDatePickerResolver();
        $descriptor = $this->makeAcfDescriptor('date_picker');
        $this->assertSame('2026-09-02', $resolver->sanitize($descriptor, '2026-09-02'));
        $this->assertSame('2026-09-02', $resolver->sanitize($descriptor, ' 2026-09-02 '));
        $this->assertSame('', $resolver->sanitize($descriptor, ''));
        $this->assertSame('', $resolver->sanitize($descriptor, null));
        $this->assertSame('', $resolver->sanitize($descriptor, 'not a date'));
    }

    public function test_resolver_validate_accepts_valid_dates_rejects_junk(): void
    {
        $resolver = new AcfDatePickerResolver();
        $descriptor = $this->makeAcfDescriptor('date_picker');
        $this->assertTrue($resolver->validate($descriptor, '2026-09-02')['ok']);
        $this->assertTrue($resolver->validate($descriptor, '')['ok']);
        $this->assertTrue($resolver->validate($descriptor, null)['ok']);
        $this->assertFalse($resolver->validate($descriptor, '09/02/2026')['ok']);
        $this->assertFalse($resolver->validate($descriptor, '2026-02-30')['ok']);
        $this->assertFalse($resolver->validate($descriptor, ['array'])['ok']);
    }

    // ---------------------------------------------------------------------
    // 2. SharedGlobalsControlProvider — whitelist + buildDateSummary
    // ---------------------------------------------------------------------

    public function test_supported_field_types_includes_date_picker(): void
    {
        $this->assertContains('date_picker', SharedGlobalsControlProvider::supportedFieldTypes());
    }

    public function test_build_date_summary_valid(): void
    {
        $summary = SharedGlobalsControlProvider::buildDateSummary('2026-09-02');
        $this->assertNotNull($summary);
        $this->assertSame('date', $summary['family']);
        $this->assertSame('2026-09-02', $summary['iso']);
        $this->assertSame('2026-09-02', $summary['label']);
    }

    public function test_build_date_summary_null_cases(): void
    {
        $this->assertNull(SharedGlobalsControlProvider::buildDateSummary(''));
        $this->assertNull(SharedGlobalsControlProvider::buildDateSummary(null));
        $this->assertNull(SharedGlobalsControlProvider::buildDateSummary('not a date'));
        $this->assertNull(SharedGlobalsControlProvider::buildDateSummary('2026-02-30'));
        $this->assertNull(SharedGlobalsControlProvider::buildDateSummary(20260902));
    }

    // ---------------------------------------------------------------------
    // 3. SharedGlobalsDescriptorFactory — date_picker branch
    // ---------------------------------------------------------------------

    public function test_factory_builds_date_descriptor_for_date_picker_field(): void
    {
        $factory = new SharedGlobalsDescriptorFactory();
        $descriptor = $factory->build('ves_test', [], [
            'name' => 'site_business_type',
            'key' => 'field_site_business_type',
            'type' => 'date_picker',
            'label' => 'Business anniversary',
            'parent' => '',
        ]);
        $this->assertSame('acf_field', $descriptor->source['type']);
        $this->assertSame('date_picker', $descriptor->source['field_type']);
        $this->assertSame('date', $descriptor->ui['input']);
        $this->assertSame('acf_date_picker', $descriptor->resolver['name']);
        $this->assertSame('shared_field', $descriptor->mutation['contract']);
        $this->assertSame('scalar', $descriptor->mutation['kind']);
        $this->assertSame('toolbar_shared_global_option_date', $descriptor->source['source_context']);
    }

    // ---------------------------------------------------------------------
    // 4. Provider integration
    // ---------------------------------------------------------------------

    public function test_provider_emits_available_date_picker_record(): void
    {
        $provider = $this->makeSharedGlobalsProvider(
            ['site_business_type'],
            [
                'site_business_type' => [
                    'name' => 'site_business_type',
                    'key' => 'field_site_business_type',
                    'type' => 'date_picker',
                    'label' => 'Business anniversary',
                    'parent' => '',
                ],
            ]
        );
        $registry = new ControlRegistry();
        $registry->registerProvider($provider);
        $items = $registry->listControls();

        $this->assertCount(1, $items);
        $this->assertSame('available', $items[0]['status']);
        // ControlRecord whitelist has no date bucket — falls into 'other'.
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
