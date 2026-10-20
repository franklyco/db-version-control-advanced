<?php

use Dbvc\VisualEditor\Permissions\CapabilityManager;
use Dbvc\VisualEditor\Registry\ControlRegistry;
use Dbvc\VisualEditor\Registry\Providers\SharedGlobalsControlProvider;
use Dbvc\VisualEditor\Registry\Providers\SharedGlobalsDescriptorFactory;

/**
 * R5.1-a — text-family unlock for the Brand Control Center.
 *
 * Covers the three surfaces R5.1-a touches on top of the R4-A / R3-B stack:
 *   1. SharedGlobalsControlProvider — supportedFieldTypes / mapFieldFamily
 *      static helpers whitelist text/textarea/url/email/number and collapse
 *      them onto ControlRecord's `'text'` family bucket; getControls emits
 *      matching records with status='available'.
 *   2. SharedGlobalsDescriptorFactory — text-family types build a
 *      `shared_field` descriptor routed to AcfTextResolver
 *      (`source.type='acf_field'`, `ui.input='text'|'textarea'`,
 *      `resolver.name='acf_text'`, `mutation.contract='shared_field'`,
 *      kind='scalar'). Relationship / post_object stay on the R3-C-1 path
 *      unchanged.
 *   3. SharedGlobalsControlProvider::buildValueSummary — text-family
 *      values collapse to a `{family:'text', preview, charCount, truncated}`
 *      shape (preview capped at 32 chars); empty / non-scalar values return
 *      null so the drawer renders an empty chip slot.
 *
 * No ACF loaded; every provider seam is a closure that returns a fixture.
 */
final class VisualEditorControlCenterR51ATest extends WP_UnitTestCase
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
        remove_all_filters('dbvc_visual_editor_control_center_description');
        parent::tear_down();
    }

    // ---------------------------------------------------------------------
    // 1. SharedGlobalsControlProvider — helpers + widened whitelist
    // ---------------------------------------------------------------------

    public function test_supported_field_types_covers_relationship_and_text_family(): void
    {
        $types = SharedGlobalsControlProvider::supportedFieldTypes();
        $this->assertContains('relationship', $types);
        $this->assertContains('post_object', $types);
        $this->assertContains('text', $types);
        $this->assertContains('textarea', $types);
        $this->assertContains('url', $types);
        $this->assertContains('email', $types);
        $this->assertContains('number', $types);
    }

    public function test_map_field_family_collapses_text_family_onto_text_bucket(): void
    {
        $this->assertSame('text', SharedGlobalsControlProvider::mapFieldFamily('text'));
        $this->assertSame('text', SharedGlobalsControlProvider::mapFieldFamily('textarea'));
        $this->assertSame('text', SharedGlobalsControlProvider::mapFieldFamily('url'));
        $this->assertSame('text', SharedGlobalsControlProvider::mapFieldFamily('email'));
        $this->assertSame('text', SharedGlobalsControlProvider::mapFieldFamily('number'));
        $this->assertSame('relationship', SharedGlobalsControlProvider::mapFieldFamily('relationship'));
        $this->assertSame('post_object', SharedGlobalsControlProvider::mapFieldFamily('post_object'));
        $this->assertSame('other', SharedGlobalsControlProvider::mapFieldFamily('date_picker'));
    }

    public function test_get_controls_emits_available_record_for_text_field(): void
    {
        $provider = $this->makeSharedGlobalsProvider(
            ['site_tagline'],
            [
                'site_tagline' => [
                    'name' => 'site_tagline',
                    'key' => 'field_site_tagline',
                    'type' => 'text',
                    'label' => 'Site Tagline',
                    'parent' => '',
                ],
            ]
        );

        $registry = new ControlRegistry();
        $registry->registerProvider($provider);
        $items = $registry->listControls();

        $this->assertCount(1, $items);
        $this->assertSame('shared_globals:site_tagline', $items[0]['publicId']);
        $this->assertSame('text', $items[0]['fieldFamily']);
        $this->assertSame('available', $items[0]['status']);
    }

    public function test_get_controls_still_emits_relationship_records_with_native_family(): void
    {
        $provider = $this->makeSharedGlobalsProvider(
            ['related_posts'],
            [
                'related_posts' => [
                    'name' => 'related_posts',
                    'key' => 'field_related_posts',
                    'type' => 'relationship',
                    'label' => 'Related Posts',
                    'parent' => '',
                ],
            ]
        );

        $registry = new ControlRegistry();
        $registry->registerProvider($provider);
        $items = $registry->listControls();

        $this->assertCount(1, $items);
        // Relationship / post_object keep their native fieldFamily so the
        // drawer can still filter on them via the family chip.
        $this->assertSame('relationship', $items[0]['fieldFamily']);
    }

    public function test_get_controls_skips_unsupported_field_types(): void
    {
        $provider = $this->makeSharedGlobalsProvider(
            ['brand_color', 'site_tagline'],
            [
                'brand_color' => [
                    'name' => 'brand_color',
                    'key' => 'field_brand_color',
                    // `taxonomy` — a real ACF field type outside every
                    // supported list, so SharedGlobalsControlProvider
                    // continues to skip it. Every other family is now
                    // wired through the factory.
                    'type' => 'taxonomy',
                    'label' => 'Brand Color',
                    'parent' => '',
                ],
                'site_tagline' => [
                    'name' => 'site_tagline',
                    'key' => 'field_site_tagline',
                    'type' => 'text',
                    'label' => 'Site Tagline',
                    'parent' => '',
                ],
            ]
        );

        $registry = new ControlRegistry();
        $registry->registerProvider($provider);
        $items = $registry->listControls();

        $this->assertCount(1, $items);
        $this->assertSame('shared_globals:site_tagline', $items[0]['publicId']);
    }

    // ---------------------------------------------------------------------
    // 2. SharedGlobalsDescriptorFactory — shared_field branch
    // ---------------------------------------------------------------------

    /**
     * @dataProvider textFamilyTypesProvider
     */
    public function test_factory_builds_shared_field_descriptor_for_text_family_type(string $field_type, string $expected_input): void
    {
        $factory = new SharedGlobalsDescriptorFactory();
        $descriptor = $factory->build('ves_test', [], [
            'name' => 'sample_text',
            'key' => 'field_sample_text',
            'type' => $field_type,
            'label' => 'Sample Text',
            'parent' => '',
        ]);

        $this->assertSame('editable', $descriptor->status);
        $this->assertSame('shared_entity', $descriptor->scope);

        // source shape
        $this->assertSame('acf_field', $descriptor->source['type']);
        $this->assertSame('sample_text', $descriptor->source['field_name']);
        $this->assertSame('sample_text', $descriptor->source['field_selector']);
        $this->assertSame('field_sample_text', $descriptor->source['field_key']);
        $this->assertSame($field_type, $descriptor->source['field_type']);
        $this->assertArrayNotHasKey('reference_post_types', $descriptor->source);
        $this->assertArrayNotHasKey('query_result_ids', $descriptor->source);

        // ui / resolver / mutation shape
        $this->assertSame($expected_input, $descriptor->ui['input']);
        $this->assertSame('acf_text', $descriptor->resolver['name']);
        $this->assertSame('shared_field', $descriptor->mutation['contract']);
        $this->assertSame('scalar', $descriptor->mutation['kind']);
        $this->assertSame('field', $descriptor->mutation['target']);
        $this->assertSame('field', $descriptor->mutation['renderContext']);
        $this->assertTrue($descriptor->mutation['reloadAfterSave']);
    }

    public function textFamilyTypesProvider(): array
    {
        return [
            'text' => ['text', 'text'],
            'textarea' => ['textarea', 'textarea'],
            'url' => ['url', 'text'],
            'email' => ['email', 'text'],
            'number' => ['number', 'text'],
        ];
    }

    public function test_factory_relationship_path_remains_reference_collection(): void
    {
        $factory = new SharedGlobalsDescriptorFactory();
        $descriptor = $factory->build('ves_test', [], [
            'name' => 'related_posts',
            'key' => 'field_related_posts',
            'type' => 'relationship',
            'label' => 'Related Posts',
            'parent' => '',
            'multiple' => 1,
            'post_type' => ['post'],
        ]);

        // R5.1-a must not affect the R3-C-1 relationship shape.
        $this->assertSame('acf_collection_field', $descriptor->source['type']);
        $this->assertSame('reference_collection', $descriptor->ui['input']);
        $this->assertSame('acf_reference_collection', $descriptor->resolver['name']);
        $this->assertSame('shared_relationship_collection', $descriptor->mutation['contract']);
        $this->assertSame('collection', $descriptor->mutation['kind']);
    }

    // ---------------------------------------------------------------------
    // 3. SharedGlobalsControlProvider — buildValueSummary text shape
    // ---------------------------------------------------------------------

    public function test_build_value_summary_returns_text_shape_short_value(): void
    {
        $provider = $this->makeSharedGlobalsProvider(
            ['site_tagline'],
            [
                'site_tagline' => [
                    'name' => 'site_tagline',
                    'key' => 'field_site_tagline',
                    'type' => 'text',
                    'label' => 'Site Tagline',
                    'parent' => '',
                ],
            ],
            static function ($name) {
                return $name === 'site_tagline' ? 'Websites that grow with you' : null;
            }
        );

        $registry = new ControlRegistry();
        $registry->registerProvider($provider);
        $record = $registry->getVisibleRecord('shared_globals:site_tagline');
        $this->assertNotNull($record);

        $summary = $registry->buildValueSummaryForRecord($record, $this->session_id);
        $this->assertIsArray($summary);
        $this->assertSame('text', $summary['family']);
        $this->assertSame('Websites that grow with you', $summary['preview']);
        $this->assertSame(27, $summary['charCount']);
        $this->assertFalse($summary['truncated']);
    }

    public function test_build_value_summary_truncates_long_text_at_thirty_two_chars(): void
    {
        // Deliberately > 32 chars so the truncation branch triggers.
        $long = 'This tagline is intentionally quite long to force the summary preview to truncate.';
        $provider = $this->makeSharedGlobalsProvider(
            ['site_tagline'],
            [
                'site_tagline' => [
                    'name' => 'site_tagline',
                    'key' => 'field_site_tagline',
                    'type' => 'text',
                    'label' => 'Site Tagline',
                    'parent' => '',
                ],
            ],
            static function ($name) use ($long) {
                return $name === 'site_tagline' ? $long : null;
            }
        );

        $registry = new ControlRegistry();
        $registry->registerProvider($provider);
        $record = $registry->getVisibleRecord('shared_globals:site_tagline');
        $this->assertNotNull($record);

        $summary = $registry->buildValueSummaryForRecord($record, $this->session_id);
        $this->assertIsArray($summary);
        $this->assertSame('text', $summary['family']);
        $this->assertSame(mb_substr($long, 0, 32, 'UTF-8'), $summary['preview']);
        $this->assertSame(mb_strlen($long, 'UTF-8'), $summary['charCount']);
        $this->assertTrue($summary['truncated']);
    }

    public function test_build_value_summary_null_when_text_value_empty(): void
    {
        $provider = $this->makeSharedGlobalsProvider(
            ['site_tagline'],
            [
                'site_tagline' => [
                    'name' => 'site_tagline',
                    'key' => 'field_site_tagline',
                    'type' => 'text',
                    'label' => 'Site Tagline',
                    'parent' => '',
                ],
            ],
            static function () {
                return '';
            }
        );

        $registry = new ControlRegistry();
        $registry->registerProvider($provider);
        $record = $registry->getVisibleRecord('shared_globals:site_tagline');
        $this->assertNotNull($record);
        $this->assertNull($registry->buildValueSummaryForRecord($record, $this->session_id));
    }

    public function test_build_value_summary_null_when_text_value_non_scalar(): void
    {
        $provider = $this->makeSharedGlobalsProvider(
            ['site_tagline'],
            [
                'site_tagline' => [
                    'name' => 'site_tagline',
                    'key' => 'field_site_tagline',
                    'type' => 'text',
                    'label' => 'Site Tagline',
                    'parent' => '',
                ],
            ],
            static function () {
                return ['unexpected' => 'array']; // non-scalar → collapse to null
            }
        );

        $registry = new ControlRegistry();
        $registry->registerProvider($provider);
        $record = $registry->getVisibleRecord('shared_globals:site_tagline');
        $this->assertNotNull($record);
        $this->assertNull($registry->buildValueSummaryForRecord($record, $this->session_id));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @param array<int, string>                       $configured_names
     * @param array<string, array<string, mixed>|bool> $field_objects
     * @param callable(string):mixed|null              $option_value_resolver
     */
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
