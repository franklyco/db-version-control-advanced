<?php

use Dbvc\VisualEditor\Bricks\DynamicBackgroundCssReader;
use Dbvc\VisualEditor\Bricks\DynamicDataInspector;
use Dbvc\VisualEditor\Bricks\GlobalClassBackgroundIndex;
use Dbvc\VisualEditor\Registry\EditableDescriptor;

/**
 * Dynamic background images bound through a Bricks global class (or an element setting)
 * render as stylesheet rules, never inline styles. These cover discovery and verification.
 */
final class VisualEditorGlobalClassBackgroundTest extends WP_UnitTestCase
{
    private const DYNAMIC_CSS = '.hero-core__section.brxe-section {background-color: var(--bg) } '
        . '.hero-core__section.brxe-section {background-image: none} '
        . '.hero-core-archives__section.brxe-section {background-image: url("https://example.test/uploads/hero.jpg")} '
        . '@media (max-width: 767px) { .hero-core-archives__section.brxe-section {background-image: url("https://example.test/uploads/mobile.jpg")} } '
        . "\n/* TEMPLATE SHORTCODE CSS (ID: 2669) */\n"
        . '#brxe-femzez {background-image: url("https://example.test/uploads/element.jpg")}';

    /**
     * @return array<int, array<string, mixed>>
     */
    private function globalClasses(): array
    {
        return [
            [
                'id' => 'sfxjzc',
                'name' => 'hero-core__section',
                'settings' => ['_background' => ['image' => ['useDynamicData' => '{acf_hero_section_image_group_hero_image}', 'size' => 'full']]],
            ],
            [
                'id' => 'rafyjd',
                'name' => '--bg-gradient-dark-light',
                'settings' => ['_gradient' => ['applyTo' => 'overlay']],
            ],
            [
                'id' => 'tksyqe',
                'name' => 'hero-core-archives__section',
                'settings' => ['_background' => ['image' => ['useDynamicData' => '{acf_services_archive_image}', 'size' => 'full']]],
            ],
            [
                'id' => 'echocl',
                'name' => 'case-studies-card',
                'settings' => ['_background' => ['image' => ['useDynamicData' => '{echo:fco_first_img(1)}']]],
            ],
        ];
    }

    private function inspector(string $css = self::DYNAMIC_CSS): DynamicDataInspector
    {
        $classes = $this->globalClasses();

        return new DynamicDataInspector(
            new GlobalClassBackgroundIndex(static function () use ($classes) {
                return $classes;
            }),
            new DynamicBackgroundCssReader(static function () use ($css) {
                return $css;
            })
        );
    }

    private function element(array $settings, string $name = 'section'): object
    {
        return (object) [
            'id' => 'zpyprh',
            'name' => $name,
            'settings' => $settings,
        ];
    }

    public function test_index_returns_dynamic_background_classes_in_element_order(): void
    {
        $classes = $this->globalClasses();
        $index = new GlobalClassBackgroundIndex(static function () use ($classes) {
            return $classes;
        });

        $candidates = $index->candidatesFor(['tksyqe', 'rafyjd', 'missing', 'sfxjzc']);

        $this->assertSame(['tksyqe', 'sfxjzc'], array_column($candidates, 'id'));
        $this->assertSame('{acf_services_archive_image}', $candidates[0]['expression']);
        $this->assertSame('full', $candidates[0]['size']);
    }

    public function test_reader_returns_last_base_rule_and_ignores_media_queries(): void
    {
        $reader = new DynamicBackgroundCssReader(static function () {
            return self::DYNAMIC_CSS;
        });

        $hero = $reader->findBackgroundImage(['.hero-core-archives__section.brxe-section']);
        $this->assertSame('https://example.test/uploads/hero.jpg', $hero['url']);

        $none = $reader->findBackgroundImage(['.hero-core__section.brxe-section']);
        $this->assertSame('', $none['url']);
        $this->assertLessThan($hero['offset'], $none['offset']);

        $element = $reader->findBackgroundImage(['#brxe-femzez', '.brxe-femzez']);
        $this->assertSame('https://example.test/uploads/element.jpg', $element['url']);

        $this->assertNull($reader->findBackgroundImage(['.cta-2__container.brxe-container']));
    }

    public function test_inspector_picks_the_class_whose_emitted_rule_wins_the_cascade(): void
    {
        $candidate = $this->inspector()->inspect($this->element([
            '_cssGlobalClasses' => ['sfxjzc', 'rafyjd', 'tksyqe'],
        ]));

        $this->assertTrue($candidate['supported']);
        $this->assertSame('acf_field', $candidate['source_type']);
        $this->assertSame('services_archive_image', $candidate['field_name']);
        $this->assertSame('background_image', $candidate['render_context']);
        $this->assertSame('style', $candidate['render_attribute']);
        $this->assertSame('global_class', $candidate['background_source']);
        $this->assertSame('tksyqe', $candidate['global_class_id']);
        $this->assertSame('hero-core-archives__section', $candidate['global_class_name']);
        $this->assertSame('full', $candidate['media_size']);
    }

    public function test_inspector_cascade_follows_stylesheet_order_not_class_order(): void
    {
        $css = '.hero-core-archives__section.brxe-section {background-image: url("a.jpg")} '
            . '.hero-core__section.brxe-section {background-image: url("b.jpg")}';

        $candidate = $this->inspector($css)->inspect($this->element([
            '_cssGlobalClasses' => ['sfxjzc', 'tksyqe'],
        ]));

        $this->assertSame('sfxjzc', $candidate['global_class_id']);
        $this->assertSame('hero_section_image_group_hero_image', $candidate['field_name']);
    }

    public function test_inspector_falls_back_to_last_bound_class_before_css_is_emitted(): void
    {
        $candidate = $this->inspector('')->inspect($this->element([
            '_cssGlobalClasses' => ['tksyqe', 'sfxjzc'],
        ]));

        $this->assertSame('sfxjzc', $candidate['global_class_id']);
    }

    public function test_element_level_background_overrides_class_binding(): void
    {
        $inspector = $this->inspector();

        $static = $inspector->inspect($this->element([
            '_cssGlobalClasses' => ['tksyqe'],
            '_background' => ['image' => ['id' => 12, 'url' => 'https://example.test/static.jpg']],
        ]));
        $this->assertFalse($static['supported']);

        $dynamic = $inspector->inspect($this->element([
            '_cssGlobalClasses' => ['tksyqe'],
            '_background' => ['image' => ['useDynamicData' => '{acf_hero_section_image_group_hero_image}']],
        ]));
        $this->assertSame('hero_section_image_group_hero_image', $dynamic['field_name']);
        $this->assertArrayNotHasKey('background_source', $dynamic);
    }

    public function test_unsupported_winning_class_expression_is_not_exposed(): void
    {
        $css = '.case-studies-card.brxe-block {background-image: url("x.jpg")}';

        $candidate = $this->inspector($css)->inspect($this->element([
            '_cssGlobalClasses' => ['tksyqe', 'echocl'],
        ], 'block'));

        $this->assertFalse($candidate['supported']);
    }

    public function test_text_binding_still_takes_precedence_over_class_background(): void
    {
        $candidate = $this->inspector()->inspect($this->element([
            'text' => '{acf_services_archive_title}',
            '_cssGlobalClasses' => ['tksyqe'],
        ], 'heading'));

        $this->assertSame('services_archive_title', $candidate['field_name']);
        $this->assertArrayNotHasKey('background_source', $candidate);
    }

    public function test_verification_reads_emitted_rule_when_no_inline_style(): void
    {
        $reflection = new ReflectionClass(\Dbvc\VisualEditor\Bricks\ElementInstrumentationService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $reader = $reflection->getProperty('background_css');
        $reader->setAccessible(true);
        $reader->setValue($service, new DynamicBackgroundCssReader(static function () {
            return self::DYNAMIC_CSS;
        }));
        $extract = $reflection->getMethod('extractComparableRenderValue');
        $extract->setAccessible(true);

        $class_descriptor = $this->descriptor([
            'element_id' => 'zpyprh',
            'element_name' => 'section',
            'context' => 'background_image',
            'attribute' => 'style',
            'background_source' => 'global_class',
            'global_class_name' => 'hero-core-archives__section',
        ]);
        $tag = '<section id="brxe-zpyprh" class="brxe-section hero-core-archives__section" data-dbvc-ve="ve_test">';
        $this->assertSame('https://example.test/uploads/hero.jpg', $extract->invoke($service, $tag, $class_descriptor));

        $element_descriptor = $this->descriptor([
            'element_id' => 'femzez',
            'element_name' => 'section',
            'context' => 'background_image',
            'attribute' => 'style',
        ]);
        $this->assertSame('https://example.test/uploads/element.jpg', $extract->invoke($service, '<section id="brxe-femzez">', $element_descriptor));

        $inline_tag = '<section id="brxe-femzez" style="background-image: url(&quot;https://example.test/inline.jpg&quot;)">';
        $this->assertSame('https://example.test/inline.jpg', $extract->invoke($service, $inline_tag, $element_descriptor));

        $missing = $this->descriptor([
            'element_id' => 'nxscop',
            'element_name' => 'container',
            'context' => 'background_image',
            'background_source' => 'global_class',
            'global_class_name' => 'cta-2__container',
        ]);
        $this->assertSame('', $extract->invoke($service, '<div id="brxe-nxscop">', $missing));
    }

    /**
     * Bricks 2.3.x output for dynamic backgrounds inside query loops: per-item rules scoped with
     * the loop identifier it also stamps on the element, alongside the page-context class rule.
     */
    private const LOOP_CSS = '.mm-core__post.brxe-block {background-image: url("https://example.test/uploads/page.jpg")} '
        . '.brxe-qjyxen.brxe-block[data-query-loop-index="0"] {background-image: url("https://example.test/uploads/team-a.jpg")} '
        . '.brxe-qjyxen.brxe-block[data-query-loop-index="1"] {background-image: url("https://example.test/uploads/team-b.jpg")} '
        . '.brxe-qjyxen.brxe-block[data-query-loop-index="2"] {background-image: none} '
        . '.brxe-loopel .brxe-cardbg.brxe-div[data-query-loop-index="gjzhgw:1:loopel:3"] {background-image: url("https://example.test/uploads/nested.jpg")} '
        . '.brxe-otherl .brxe-cardbg.brxe-div[data-query-loop-index="gjzhgw:1:loopel:3"] {background-image: url("https://example.test/uploads/other-loop.jpg")} '
        . '.team-card--inline.brxe-div[data-query-loop-index="4"] {background-image: url("https://example.test/uploads/class-item.jpg")}';

    public function test_loop_element_background_matches_the_rule_for_the_rendered_loop_index(): void
    {
        $service = $this->serviceWithCss(self::LOOP_CSS);
        $root = $this->loopDescriptor(['element_id' => 'qjyxen', 'element_name' => 'block'], 'qjyxen');

        $this->assertSame('https://example.test/uploads/team-a.jpg', $this->extract($service, '<div class="brxe-qjyxen brxe-block" data-query-loop-index="0" data-dbvc-ve="ve_test">', $root));
        $this->assertSame('https://example.test/uploads/team-b.jpg', $this->extract($service, '<div class="brxe-qjyxen brxe-block" data-query-loop-index="1" data-dbvc-ve="ve_test">', $root));
        // Bricks emits `none` for an item whose field is empty: verifiable as an empty background.
        $this->assertSame('', $this->extract($service, '<div class="brxe-qjyxen brxe-block" data-query-loop-index="2">', $root));
        $this->assertFalse($this->unverifiable($service, '<div class="brxe-qjyxen brxe-block" data-query-loop-index="2">', $root));
    }

    public function test_loop_descendant_background_is_scoped_by_its_loop_element_and_nested_index(): void
    {
        $service = $this->serviceWithCss(self::LOOP_CSS);
        $descendant = $this->loopDescriptor(['element_id' => 'cardbg', 'element_name' => 'div'], 'loopel');

        $this->assertSame(
            'https://example.test/uploads/nested.jpg',
            $this->extract($service, '<div class="brxe-cardbg brxe-div" data-query-loop-index="gjzhgw:1:loopel:3">', $descendant)
        );
    }

    public function test_loop_class_background_matches_only_a_per_item_class_rule(): void
    {
        $service = $this->serviceWithCss(self::LOOP_CSS);
        $class_render = [
            'element_id' => 'cardwr',
            'element_name' => 'div',
            'background_source' => 'global_class',
            'global_class_name' => 'team-card--inline',
        ];

        $this->assertSame(
            'https://example.test/uploads/class-item.jpg',
            $this->extract($service, '<div class="brxe-cardwr brxe-div team-card--inline" data-query-loop-index="4">', $this->loopDescriptor($class_render, 'cardwr'))
        );

        // Bricks resolves the unscoped class rule against the page, not the loop item: never used for a loop item.
        $page_rule_only = $this->loopDescriptor([
            'element_id' => 'vlnjfz',
            'element_name' => 'block',
            'background_source' => 'global_class',
            'global_class_name' => 'mm-core__post',
        ], 'vlnjfz');
        $tag = '<div class="brxe-vlnjfz brxe-block mm-core__post" data-dbvc-ve="ve_test">';
        $this->assertSame('', $this->extract($service, $tag, $page_rule_only));
        $this->assertTrue($this->unverifiable($service, $tag, $page_rule_only));
        $this->assertTrue($this->unverifiable($service, '<div class="brxe-vlnjfz brxe-block mm-core__post" data-query-loop-index="0">', $page_rule_only));
    }

    public function test_loop_background_without_a_matching_item_rule_fails_closed(): void
    {
        $service = $this->serviceWithCss(self::LOOP_CSS);
        $root = $this->loopDescriptor(['element_id' => 'qjyxen', 'element_name' => 'block'], 'qjyxen');

        // No loop index on the rendered element, an index Bricks emitted nothing for, or a hostile value.
        $this->assertTrue($this->unverifiable($service, '<div class="brxe-qjyxen brxe-block">', $root));
        $this->assertTrue($this->unverifiable($service, '<div class="brxe-qjyxen brxe-block" data-query-loop-index="9">', $root));
        $this->assertTrue($this->unverifiable($service, '<div class="brxe-qjyxen brxe-block" data-query-loop-index="0&quot;] , *[x">', $root));

        // Same nested index under a different loop element is not this element's rule.
        $foreign = $this->loopDescriptor(['element_id' => 'cardbg', 'element_name' => 'div'], 'thirdl');
        $this->assertTrue($this->unverifiable($service, '<div class="brxe-cardbg brxe-div" data-query-loop-index="gjzhgw:1:loopel:3">', $foreign));

        // An inline style is still render truth.
        $inline = '<div class="brxe-qjyxen brxe-block" style="background-image: url(&quot;https://example.test/inline.jpg&quot;)">';
        $this->assertFalse($this->unverifiable($service, $inline, $root));
        $this->assertSame('https://example.test/inline.jpg', $this->extract($service, $inline, $root));
    }

    public function test_non_loop_descriptors_keep_unscoped_matching(): void
    {
        $service = $this->serviceWithCss(self::LOOP_CSS);
        $descriptor = $this->descriptor([
            'element_id' => 'vlnjfz',
            'element_name' => 'block',
            'context' => 'background_image',
            'background_source' => 'global_class',
            'global_class_name' => 'mm-core__post',
        ]);

        $this->assertSame('https://example.test/uploads/page.jpg', $this->extract($service, '<div class="brxe-vlnjfz brxe-block mm-core__post">', $descriptor));
        $this->assertFalse($this->unverifiable($service, '<div class="brxe-vlnjfz brxe-block mm-core__post">', $descriptor));
    }

    public function test_query_loop_trail_attribute_group_is_never_instrumented(): void
    {
        $post_id = self::factory()->post->create(['post_status' => 'publish']);
        $attachment_id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg', $post_id);
        set_post_thumbnail($post_id, $attachment_id);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->go_to(get_permalink($post_id));

        $page_context = new \Dbvc\VisualEditor\Context\PageContextResolver();
        $service = new \Dbvc\VisualEditor\Bricks\ElementInstrumentationService(
            new \Dbvc\VisualEditor\Registry\EditableRegistry($page_context),
            $page_context,
            new \Dbvc\VisualEditor\Resolvers\ResolverRegistry()
        );
        $element = (object) [
            'id' => 'qjyxen',
            'name' => 'block',
            'settings' => ['hasLoop' => true, '_background' => ['image' => ['useDynamicData' => '{featured_image}']]],
        ];

        // The loop element's own attribute group is instrumented ...
        $root = $service->instrumentAttributes(['_root' => ['class' => ['brxe-block']]], '_root', $element);
        $this->assertArrayHasKey('data-dbvc-ve', $root['_root'], 'Precondition: the element background is instrumentable here.');

        // ... but the trail Bricks renders after the loop ends never is.
        $trail = ['trail' => ['class' => ['brx-query-trail']]];
        $this->assertSame($trail, $service->instrumentAttributes($trail, 'trail', $element));
    }

    private function serviceWithCss(string $css): object
    {
        $reflection = new ReflectionClass(\Dbvc\VisualEditor\Bricks\ElementInstrumentationService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $reader = $reflection->getProperty('background_css');
        $reader->setAccessible(true);
        $reader->setValue($service, new DynamicBackgroundCssReader(static function () use ($css) {
            return $css;
        }));

        return $service;
    }

    private function extract(object $service, string $tag, EditableDescriptor $descriptor): string
    {
        $method = new ReflectionMethod($service, 'extractComparableRenderValue');
        $method->setAccessible(true);

        return $method->invoke($service, $tag, $descriptor);
    }

    private function unverifiable(object $service, string $tag, EditableDescriptor $descriptor): bool
    {
        $method = new ReflectionMethod($service, 'isUnverifiableLoopBackground');
        $method->setAccessible(true);

        return $method->invoke($service, $tag, $descriptor);
    }

    private function loopDescriptor(array $render, string $query_element_id): EditableDescriptor
    {
        $render += ['context' => 'background_image', 'attribute' => 'style', 'loop_signature' => $query_element_id . ':0:post:1|q|' . $query_element_id];

        return new EditableDescriptor('ve_test', 'editable', 'related_entity', [], $render, [], [], [], [], [], ['active' => true, 'query_element_id' => $query_element_id], [], []);
    }

    private function descriptor(array $render): EditableDescriptor
    {
        return new EditableDescriptor('ve_test', 'editable', 'current_entity', [], $render, [], [], [], [], [], [], [], []);
    }
}
