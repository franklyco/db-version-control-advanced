<?php

use Dbvc\VisualEditor\Permissions\CapabilityManager;
use Dbvc\VisualEditor\Registry\ControlRegistry;
use Dbvc\VisualEditor\Registry\Providers\SharedGlobalsControlProvider;
use Dbvc\VisualEditor\Registry\Providers\SharedGlobalsDescriptorFactory;

/**
 * R5.2 — choice / link / wysiwyg unlock for the Brand Control Center.
 *
 * Covers the three surfaces R5.2 (a + b) touches on top of the R5.1 stack:
 *   1. SharedGlobalsControlProvider — supportedFieldTypes now covers
 *      select / checkbox / radio / button_group / link / wysiwyg on top
 *      of the R5.1 text-family + R3-B relationship set. All still collapse
 *      to ControlRecord's 'other' fieldFamily bucket (whitelist rules).
 *   2. SharedGlobalsDescriptorFactory — new per-family branches on the
 *      `build()` dispatcher:
 *        - choice (select/checkbox/radio/button_group) → resolver
 *          `acf_choice`, `ui.input` `select` or `checkbox_group`, ACF
 *          `choices` map projected onto `ui.options` = [{value,label}].
 *        - link → resolver `acf_link`, `ui.input` `link`.
 *        - wysiwyg → resolver `acf_wysiwyg`, `ui.input` `richtext`.
 *      All emit `mutation.contract = 'shared_field'`, `kind='scalar'`.
 *   3. SharedGlobalsControlProvider::buildValueSummary — new per-family
 *      summary shapes:
 *        - single choice: `{family:'choice', label}` (resolved from
 *          field's choices map, falling back to raw value).
 *        - multi checkbox: `{family:'choice', count, firstLabels[≤3], hasMore}`.
 *        - link: `{family:'link', title, url}` (title falls back to host
 *          when empty; empty url → null).
 *        - wysiwyg: `{family:'wysiwyg', preview:stripped_text_at_40_chars,
 *          wordCount, truncated}`.
 *
 * No ACF loaded; every provider seam is a closure that returns a fixture.
 */
final class VisualEditorControlCenterR52Test extends WP_UnitTestCase
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
    // 1. supportedFieldTypes widening
    // ---------------------------------------------------------------------

    public function test_supported_field_types_covers_r52_families(): void
    {
        $types = SharedGlobalsControlProvider::supportedFieldTypes();
        foreach (['select', 'checkbox', 'radio', 'button_group', 'link', 'wysiwyg'] as $family) {
            $this->assertContains($family, $types, "supportedFieldTypes must include {$family}");
        }
    }

    // ---------------------------------------------------------------------
    // 2. Factory — choice / link / wysiwyg descriptor shapes
    // ---------------------------------------------------------------------

    /**
     * @dataProvider choiceFieldTypesProvider
     */
    public function test_factory_builds_choice_descriptor_for_choice_family_type(string $field_type, string $expected_input): void
    {
        $factory = new SharedGlobalsDescriptorFactory();
        $descriptor = $factory->build('ves_test', [], [
            'name' => 'brand_tier',
            'key' => 'field_brand_tier',
            'type' => $field_type,
            'label' => 'Brand tier',
            'parent' => '',
            'choices' => [
                'gold' => 'Gold',
                'silver' => 'Silver',
                'bronze' => 'Bronze',
            ],
        ]);

        $this->assertSame('acf_field', $descriptor->source['type']);
        $this->assertSame($field_type, $descriptor->source['field_type']);
        $this->assertSame($expected_input, $descriptor->ui['input']);
        $this->assertSame('acf_choice', $descriptor->resolver['name']);
        $this->assertSame('shared_field', $descriptor->mutation['contract']);
        $this->assertSame('scalar', $descriptor->mutation['kind']);
        // Options projection — the ACF choices map lands on ui.options as
        // [{value,label}] shape the panel controllers read.
        $this->assertIsArray($descriptor->ui['options']);
        $this->assertCount(3, $descriptor->ui['options']);
        $this->assertSame('gold', $descriptor->ui['options'][0]['value']);
        $this->assertSame('Gold', $descriptor->ui['options'][0]['label']);
    }

    public function choiceFieldTypesProvider(): array
    {
        return [
            'select' => ['select', 'select'],
            'radio' => ['radio', 'select'],
            'button_group' => ['button_group', 'select'],
            'checkbox' => ['checkbox', 'checkbox_group'],
        ];
    }

    public function test_factory_builds_link_descriptor_for_link_family(): void
    {
        $factory = new SharedGlobalsDescriptorFactory();
        $descriptor = $factory->build('ves_test', [], [
            'name' => 'homepage_cta',
            'key' => 'field_homepage_cta',
            'type' => 'link',
            'label' => 'Homepage CTA',
            'parent' => '',
        ]);

        $this->assertSame('acf_field', $descriptor->source['type']);
        $this->assertSame('link', $descriptor->source['field_type']);
        $this->assertSame('link', $descriptor->ui['input']);
        $this->assertSame('acf_link', $descriptor->resolver['name']);
        $this->assertSame('shared_field', $descriptor->mutation['contract']);
        $this->assertArrayNotHasKey('options', $descriptor->ui);
    }

    public function test_factory_builds_wysiwyg_descriptor_for_wysiwyg_family(): void
    {
        $factory = new SharedGlobalsDescriptorFactory();
        $descriptor = $factory->build('ves_test', [], [
            'name' => 'about_body',
            'key' => 'field_about_body',
            'type' => 'wysiwyg',
            'label' => 'About body',
            'parent' => '',
        ]);

        $this->assertSame('acf_field', $descriptor->source['type']);
        $this->assertSame('wysiwyg', $descriptor->source['field_type']);
        $this->assertSame('richtext', $descriptor->ui['input']);
        $this->assertSame('acf_wysiwyg', $descriptor->resolver['name']);
        $this->assertSame('shared_field', $descriptor->mutation['contract']);
    }

    public function test_factory_relationship_path_still_reference_collection(): void
    {
        // Regression guard — the R5.2 factory refactor moved text into a
        // config-bag pattern; relationship must stay on the R3-C-1 shape.
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
        $this->assertSame('acf_collection_field', $descriptor->source['type']);
        $this->assertSame('reference_collection', $descriptor->ui['input']);
        $this->assertSame('shared_relationship_collection', $descriptor->mutation['contract']);
    }

    // ---------------------------------------------------------------------
    // 3. buildValueSummary — R5.2 shapes
    // ---------------------------------------------------------------------

    public function test_single_choice_summary_resolves_label_from_choices_map(): void
    {
        $summary = SharedGlobalsControlProvider::buildSingleChoiceSummary(
            'gold',
            ['choices' => ['gold' => 'Gold', 'silver' => 'Silver']]
        );
        $this->assertSame(['family' => 'choice', 'label' => 'Gold'], $summary);
    }

    public function test_single_choice_summary_falls_back_to_raw_value_for_unknown_key(): void
    {
        $summary = SharedGlobalsControlProvider::buildSingleChoiceSummary(
            'platinum',
            ['choices' => ['gold' => 'Gold']]
        );
        $this->assertSame(['family' => 'choice', 'label' => 'platinum'], $summary);
    }

    public function test_single_choice_summary_null_when_value_empty(): void
    {
        $this->assertNull(
            SharedGlobalsControlProvider::buildSingleChoiceSummary('', ['choices' => []])
        );
    }

    public function test_multi_choice_summary_returns_count_first_labels_hasmore(): void
    {
        $summary = SharedGlobalsControlProvider::buildMultiChoiceSummary(
            ['a', 'b', 'c', 'd', 'e'],
            ['choices' => ['a' => 'Alpha', 'b' => 'Beta', 'c' => 'Gamma', 'd' => 'Delta', 'e' => 'Epsilon']]
        );
        $this->assertSame('choice', $summary['family']);
        $this->assertSame(5, $summary['count']);
        $this->assertSame(['Alpha', 'Beta', 'Gamma'], $summary['firstLabels']);
        $this->assertTrue($summary['hasMore']);
    }

    public function test_multi_choice_summary_null_when_empty_array(): void
    {
        $this->assertNull(
            SharedGlobalsControlProvider::buildMultiChoiceSummary([], ['choices' => []])
        );
    }

    public function test_link_summary_prefers_title(): void
    {
        $summary = SharedGlobalsControlProvider::buildLinkSummary([
            'url' => 'https://example.com/book',
            'title' => 'Book a call',
            'target' => '_blank',
        ]);
        $this->assertSame('link', $summary['family']);
        $this->assertSame('Book a call', $summary['title']);
        $this->assertSame('https://example.com/book', $summary['url']);
    }

    public function test_link_summary_falls_back_to_host_when_title_empty(): void
    {
        $summary = SharedGlobalsControlProvider::buildLinkSummary([
            'url' => 'https://example.com/path?x=1',
            'title' => '',
        ]);
        $this->assertSame('link', $summary['family']);
        $this->assertSame('example.com', $summary['title']);
        $this->assertSame('https://example.com/path?x=1', $summary['url']);
    }

    public function test_link_summary_null_when_url_empty(): void
    {
        $this->assertNull(
            SharedGlobalsControlProvider::buildLinkSummary(['url' => '', 'title' => 'ignored'])
        );
    }

    public function test_wysiwyg_summary_strips_html_and_counts_words(): void
    {
        $html = '<p>Websites <strong>that grow</strong> with your practice.</p>';
        $summary = SharedGlobalsControlProvider::buildWysiwygSummary($html);
        $this->assertSame('wysiwyg', $summary['family']);
        $this->assertSame('Websites that grow with your practice.', $summary['preview']);
        $this->assertSame(6, $summary['wordCount']);
        $this->assertFalse($summary['truncated']);
    }

    public function test_wysiwyg_summary_truncates_long_stripped_text_at_forty_chars(): void
    {
        $html = '<p>' . str_repeat('word ', 20) . '</p>'; // 20*5 = 100 chars raw
        $summary = SharedGlobalsControlProvider::buildWysiwygSummary($html);
        $this->assertSame('wysiwyg', $summary['family']);
        $this->assertTrue($summary['truncated']);
        $length = function_exists('mb_strlen')
            ? mb_strlen($summary['preview'], 'UTF-8')
            : strlen($summary['preview']);
        $this->assertSame(40, $length);
    }

    public function test_wysiwyg_summary_null_when_stripped_empty(): void
    {
        $this->assertNull(
            SharedGlobalsControlProvider::buildWysiwygSummary('<p>  </p>')
        );
    }

    // ---------------------------------------------------------------------
    // R5.4 — buildReferenceSummary static helper (relationship / post_object)
    // ---------------------------------------------------------------------

    public function test_reference_summary_walks_first_three_titles_for_relationship(): void
    {
        $post_ids = self::factory()->post->create_many(5, ['post_status' => 'publish']);
        foreach ($post_ids as $i => $id) {
            wp_update_post([
                'ID' => $id,
                'post_title' => 'Post ' . ($i + 1),
            ]);
        }
        $summary = SharedGlobalsControlProvider::buildReferenceSummary(
            $post_ids,
            'relationship'
        );
        $this->assertSame('relationship', $summary['family']);
        $this->assertSame(5, $summary['count']);
        $this->assertCount(3, $summary['firstTitles']);
        $this->assertTrue($summary['hasMore']);
        $this->assertSame('Post 1', $summary['firstTitles'][0]);
    }

    public function test_reference_summary_family_gate_rejects_non_reference_family(): void
    {
        // Passing an out-of-family arg returns null — protects against a
        // caller mistakenly routing a text value through the reference
        // walker.
        $this->assertNull(
            SharedGlobalsControlProvider::buildReferenceSummary([1, 2], 'text')
        );
    }

    public function test_reference_summary_null_when_ids_empty(): void
    {
        $this->assertNull(
            SharedGlobalsControlProvider::buildReferenceSummary([], 'post_object')
        );
        $this->assertNull(
            SharedGlobalsControlProvider::buildReferenceSummary(null, 'post_object')
        );
        $this->assertNull(
            SharedGlobalsControlProvider::buildReferenceSummary(false, 'relationship')
        );
    }

    public function test_reference_summary_coerces_acf_associative_shape(): void
    {
        $post_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'One',
        ]);
        // Single post_object — ACF's `array` return_format shape uses
        // `{ID, post_title, …}` associative array.
        $summary = SharedGlobalsControlProvider::buildReferenceSummary(
            ['ID' => $post_id, 'post_title' => 'One'],
            'post_object'
        );
        $this->assertSame('post_object', $summary['family']);
        $this->assertSame(1, $summary['count']);
        $this->assertSame(['One'], $summary['firstTitles']);
    }

    // ---------------------------------------------------------------------
    // 4. Provider getControls integration — new families surface as available
    // ---------------------------------------------------------------------

    public function test_provider_emits_available_records_for_r52_families(): void
    {
        $provider = $this->makeSharedGlobalsProvider(
            ['tier', 'primary_link', 'about'],
            [
                'tier' => [
                    'name' => 'tier',
                    'key' => 'field_tier',
                    'type' => 'select',
                    'label' => 'Tier',
                    'parent' => '',
                    'choices' => ['gold' => 'Gold', 'silver' => 'Silver'],
                ],
                'primary_link' => [
                    'name' => 'primary_link',
                    'key' => 'field_primary_link',
                    'type' => 'link',
                    'label' => 'Primary link',
                    'parent' => '',
                ],
                'about' => [
                    'name' => 'about',
                    'key' => 'field_about',
                    'type' => 'wysiwyg',
                    'label' => 'About',
                    'parent' => '',
                ],
            ]
        );

        $registry = new ControlRegistry();
        $registry->registerProvider($provider);
        $items = $registry->listControls();

        $this->assertCount(3, $items);
        foreach ($items as $item) {
            $this->assertSame('available', $item['status']);
            // Every R5.2 family lands under ControlRecord's `other` bucket
            // — the whitelist doesn't include per-family choice/link/wysiwyg.
            $this->assertSame('other', $item['fieldFamily']);
        }
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

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
