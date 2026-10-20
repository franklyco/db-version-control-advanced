<?php

final class VisualEditorElementInstrumentationTest extends WP_UnitTestCase
{
    /**
     * @return array{0: object, 1: ReflectionMethod, 2: ReflectionMethod}
     */
    private function matcherFixture(): array
    {
        $reflection = new ReflectionClass(\Dbvc\VisualEditor\Bricks\ElementInstrumentationService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $all_matches = $reflection->getMethod('findBricksElementOpeningTagMatches');
        $all_matches->setAccessible(true);
        $window_match = $reflection->getMethod('findBricksElementOpeningTagWindow');
        $window_match->setAccessible(true);

        return [$service, $all_matches, $window_match];
    }

    public function test_unique_bricks_id_is_found_without_identity_class(): void
    {
        [$service, $all_matches, $window_match] = $this->matcherFixture();
        $content = '<div id="brxe-a43ea9" class="brxe-div hero__img_wrap"></div>';

        $matches = $all_matches->invoke($service, $content, 'a43ea9', true);

        $this->assertCount(1, $matches);
        $this->assertSame('<div id="brxe-a43ea9" class="brxe-div hero__img_wrap">', $matches[0]['tag']);
        $this->assertSame($matches[0], $window_match->invoke($service, $content, 'a43ea9', 0, true));
        $this->assertSame([], $window_match->invoke($service, $content, 'a43ea9', 1, true));
        $this->assertSame([], $window_match->invoke($service, $content, 'a43ea9', 0));
    }

    public function test_repeated_bricks_classes_preserve_occurrence_order(): void
    {
        [$service, $all_matches, $window_match] = $this->matcherFixture();
        $content = '<article class="card brxe-ktiaxo">First</article>'
            . '<article class="brxe-ktiaxo card">Second</article>';

        $matches = $all_matches->invoke($service, $content, 'ktiaxo');

        $this->assertCount(2, $matches);
        $this->assertSame($matches[1], $window_match->invoke($service, $content, 'ktiaxo', 1));
        $this->assertStringContainsString('Second', substr($content, $matches[1]['offset']));
    }

    public function test_identity_matching_rejects_prefix_suffix_and_unrelated_attributes(): void
    {
        [$service, $all_matches] = $this->matcherFixture();
        $content = '<div id="brxe-example-extra" class="brxe-example-other" data-ref="brxe-example"></div>'
            . '<div id="other" class="brxe-example"></div>';

        $matches = $all_matches->invoke($service, $content, 'example', true);

        $this->assertCount(1, $matches);
        $this->assertSame('<div id="other" class="brxe-example">', $matches[0]['tag']);
    }

    public function test_tag_with_matching_id_and_class_counts_as_one_occurrence(): void
    {
        [$service, $all_matches, $window_match] = $this->matcherFixture();
        $content = '<div id="brxe-shared" class="brxe-shared"></div>'
            . '<div class="brxe-shared"></div>';

        $matches = $all_matches->invoke($service, $content, 'shared', true);

        $this->assertCount(2, $matches);
        $this->assertSame($matches[1], $window_match->invoke($service, $content, 'shared', 1, true));
    }

    public function test_rendered_html_tag_is_recorded_from_the_authorized_marker_tag(): void
    {
        $reflection = new ReflectionClass(\Dbvc\VisualEditor\Bricks\ElementInstrumentationService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $remember_tag = $reflection->getMethod('rememberRenderedHtmlTag');
        $remember_tag->setAccessible(true);
        $descriptor = new \Dbvc\VisualEditor\Registry\EditableDescriptor(
            've_heading',
            'editable',
            'current_entity',
            [],
            [],
            ['field_name' => 'hero_title'],
            [],
            []
        );

        $remember_tag->invoke(
            $service,
            $descriptor,
            '<div class="brxe-heading" data-dbvc-ve="ve_heading"><h1>Title</h1></div>',
            '<h1>Title</h1>'
        );

        $this->assertSame('h1', $descriptor->render['html_tag'] ?? '');
    }

    public function test_rendered_image_tag_is_recorded_inside_a_marker_wrapper(): void
    {
        $reflection = new ReflectionClass(\Dbvc\VisualEditor\Bricks\ElementInstrumentationService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $remember_tag = $reflection->getMethod('rememberRenderedHtmlTag');
        $remember_tag->setAccessible(true);
        $descriptor = new \Dbvc\VisualEditor\Registry\EditableDescriptor(
            've_image',
            'editable',
            'current_entity',
            [],
            ['context' => 'image_src'],
            ['field_name' => 'background_image'],
            [],
            []
        );

        $remember_tag->invoke(
            $service,
            $descriptor,
            '<figure data-dbvc-ve="ve_image"><img src="example.jpg" alt=""></figure>',
            ['id' => 42]
        );

        $this->assertSame('img', $descriptor->render['html_tag'] ?? '');
    }

    public function test_rendered_html_tag_is_not_guessed_without_the_descriptor_marker(): void
    {
        $reflection = new ReflectionClass(\Dbvc\VisualEditor\Bricks\ElementInstrumentationService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $remember_tag = $reflection->getMethod('rememberRenderedHtmlTag');
        $remember_tag->setAccessible(true);
        $descriptor = new \Dbvc\VisualEditor\Registry\EditableDescriptor(
            've_image',
            'editable',
            'current_entity',
            [],
            [],
            ['field_name' => 'background_image'],
            [],
            []
        );

        $remember_tag->invoke($service, $descriptor, '<img src="example.jpg" alt="">');

        $this->assertArrayNotHasKey('html_tag', $descriptor->render);
    }
}
