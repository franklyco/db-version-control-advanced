<?php

use Dbvc\VisualEditor\Permissions\CapabilityManager;
use Dbvc\VisualEditor\Registry\ControlRegistry;
use Dbvc\VisualEditor\Registry\Providers\SharedGlobalsControlProvider;
use Dbvc\VisualEditor\Registry\Providers\SharedGlobalsDescriptorFactory;

/**
 * R5.3 — image unlock for the Brand Control Center.
 *
 * Covers the three surfaces R5.3 touches on top of the R5.1 + R5.2 stack:
 *   1. SharedGlobalsControlProvider — supportedFieldTypes now includes
 *      `'image'`; mapFieldFamily maps `'image'` onto ControlRecord's
 *      `'image'` bucket (whitelist explicitly includes it, unlike the
 *      R5.2 families which all fell into `'other'`).
 *   2. SharedGlobalsDescriptorFactory — new `'image'` branch on the
 *      `build()` dispatcher: `resolver='acf_image'`,
 *      `ui.input='media_reference'`, `mutation.contract='shared_field'`,
 *      `kind='scalar'`. Group-nesting inherits from R5.1-b `walkGroupChain`.
 *   3. SharedGlobalsControlProvider::buildValueSummary — image branch
 *      collapses attachment-id / WP_Post / associative-array shapes onto
 *      an integer id, resolves filename + thumbnail URL via WP's
 *      attachment helpers, returns
 *      `{family:'image', attachmentId, filename, thumbUrl}`.
 *      Empty / unresolvable → null so the drawer renders no chip.
 *
 * WP core's attachment helpers ARE loaded in the WP_UnitTestCase harness,
 * so we can create a real attachment fixture and assert the summary
 * resolves the filename correctly.
 */
final class VisualEditorControlCenterR53Test extends WP_UnitTestCase
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
    // 1. Whitelist + mapFieldFamily
    // ---------------------------------------------------------------------

    public function test_supported_field_types_includes_image(): void
    {
        $types = SharedGlobalsControlProvider::supportedFieldTypes();
        $this->assertContains('image', $types);
    }

    public function test_map_field_family_collapses_image_onto_image_bucket(): void
    {
        // Unlike R5.2 families (which fold into 'other'), image gets its
        // own bucket in ControlRecord's whitelist.
        $this->assertSame('image', SharedGlobalsControlProvider::mapFieldFamily('image'));
    }

    // ---------------------------------------------------------------------
    // 2. Factory image descriptor shape
    // ---------------------------------------------------------------------

    public function test_factory_builds_image_descriptor_for_image_field(): void
    {
        $factory = new SharedGlobalsDescriptorFactory();
        $descriptor = $factory->build('ves_test', [], [
            'name' => 'primary_logo',
            'key' => 'field_primary_logo',
            'type' => 'image',
            'label' => 'Primary logo',
            'parent' => '',
        ]);

        $this->assertSame('editable', $descriptor->status);
        $this->assertSame('shared_entity', $descriptor->scope);
        $this->assertSame('acf_field', $descriptor->source['type']);
        $this->assertSame('image', $descriptor->source['field_type']);
        $this->assertSame('media_reference', $descriptor->ui['input']);
        $this->assertSame('acf_image', $descriptor->resolver['name']);
        $this->assertSame('shared_field', $descriptor->mutation['contract']);
        $this->assertSame('scalar', $descriptor->mutation['kind']);
    }

    // ---------------------------------------------------------------------
    // 3. buildImageSummary — value-shape coercion + WP resolution
    // ---------------------------------------------------------------------

    public function test_build_image_summary_resolves_from_integer_attachment_id(): void
    {
        $attachment_id = $this->createSampleAttachment('logo.png');
        $summary = SharedGlobalsControlProvider::buildImageSummary($attachment_id);
        $this->assertIsArray($summary);
        $this->assertSame('image', $summary['family']);
        $this->assertSame($attachment_id, $summary['attachmentId']);
        $this->assertSame('logo.png', $summary['filename']);
        // thumbUrl may be empty in the test env when no thumbnail size is
        // registered, so just assert it's a string (never null).
        $this->assertIsString($summary['thumbUrl']);
    }

    public function test_build_image_summary_resolves_from_numeric_string(): void
    {
        $attachment_id = $this->createSampleAttachment('hero.jpg');
        $summary = SharedGlobalsControlProvider::buildImageSummary((string) $attachment_id);
        $this->assertIsArray($summary);
        $this->assertSame($attachment_id, $summary['attachmentId']);
        $this->assertSame('hero.jpg', $summary['filename']);
    }

    public function test_build_image_summary_resolves_from_acf_associative_array_shape(): void
    {
        $attachment_id = $this->createSampleAttachment('brand.svg');
        // ACF's `array` return_format shape uses a WP_Post-like array with
        // 'ID', 'url', 'filename'. Provider must coerce off 'ID'.
        $value = [
            'ID' => $attachment_id,
            'url' => 'https://example.test/wp-content/uploads/brand.svg',
            'filename' => 'brand.svg',
        ];
        $summary = SharedGlobalsControlProvider::buildImageSummary($value);
        $this->assertIsArray($summary);
        $this->assertSame($attachment_id, $summary['attachmentId']);
    }

    public function test_build_image_summary_null_for_zero_id(): void
    {
        $this->assertNull(SharedGlobalsControlProvider::buildImageSummary(0));
        $this->assertNull(SharedGlobalsControlProvider::buildImageSummary('0'));
    }

    public function test_build_image_summary_null_for_bare_false_or_empty_string(): void
    {
        $this->assertNull(SharedGlobalsControlProvider::buildImageSummary(false));
        $this->assertNull(SharedGlobalsControlProvider::buildImageSummary(''));
    }

    public function test_build_image_summary_null_for_missing_attachment(): void
    {
        // A positive int that doesn't resolve to a real attachment → null
        // (the get_post guard catches this).
        $this->assertNull(SharedGlobalsControlProvider::buildImageSummary(9999999));
    }

    // ---------------------------------------------------------------------
    // 4. Provider integration
    // ---------------------------------------------------------------------

    public function test_provider_emits_available_image_record(): void
    {
        $provider = $this->makeSharedGlobalsProvider(
            ['primary_logo'],
            [
                'primary_logo' => [
                    'name' => 'primary_logo',
                    'key' => 'field_primary_logo',
                    'type' => 'image',
                    'label' => 'Primary logo',
                    'parent' => '',
                ],
            ]
        );
        $registry = new ControlRegistry();
        $registry->registerProvider($provider);
        $items = $registry->listControls();

        $this->assertCount(1, $items);
        $this->assertSame('available', $items[0]['status']);
        $this->assertSame('image', $items[0]['fieldFamily']);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function createSampleAttachment(string $filename): int
    {
        return self::factory()->attachment->create_object(
            $filename,
            0,
            [
                'post_mime_type' => strpos($filename, '.svg') !== false ? 'image/svg+xml' : 'image/png',
                'post_type' => 'attachment',
            ]
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
