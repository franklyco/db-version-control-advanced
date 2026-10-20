<?php

namespace Bricks\Integrations\Dynamic_Data {
    if (! class_exists(__NAMESPACE__ . '\\Providers', false)) {
        final class Providers
        {
            public static $provider;

            public static function get_registered_provider($provider_name)
            {
                return $provider_name === 'acf' ? self::$provider : null;
            }
        }
    }
}

namespace {
    final class DBVC_Visual_Editor_Acf_Field_Fixture
    {
        public static $fields = [];
    }

    if (! function_exists('get_field_object')) {
        function get_field_object($identifier, $object_id = false, $format_value = true, $load_value = true)
        {
            $key = (string) $identifier . '|' . (string) $object_id;

            return DBVC_Visual_Editor_Acf_Field_Fixture::$fields[$key] ?? false;
        }
    }

    final class VisualEditorAcfFieldContextResolverTest extends WP_UnitTestCase
    {
        public function tear_down(): void
        {
            DBVC_Visual_Editor_Acf_Field_Fixture::$fields = [];
            \Bricks\Integrations\Dynamic_Data\Providers::$provider = null;
            parent::tear_down();
        }

        public function test_post_specific_nested_selector_wins_over_duplicate_provider_tag_field(): void
        {
            $post_id = self::factory()->post->create(['post_type' => 'page']);
            $selector = 'hero_section_hero_h1';
            $wysiwyg_field = [
                'key' => 'field_wysiwyg_hero_h1',
                'name' => 'hero_h1',
                'label' => 'Hero H1',
                'type' => 'wysiwyg',
                'parent' => 'field_core_hero_section',
            ];
            $ambiguous_provider_field = [
                'key' => 'field_text_hero_h1',
                'name' => 'hero_h1',
                'label' => 'Hero H1',
                'type' => 'text',
                'parent' => 'field_technologies_hero_section',
            ];

            DBVC_Visual_Editor_Acf_Field_Fixture::$fields[$selector . '|' . $post_id] = $wysiwyg_field;
            \Bricks\Integrations\Dynamic_Data\Providers::$provider = new class($ambiguous_provider_field, $post_id) {
                private $field;
                private $post_id;

                public function __construct(array $field, $post_id)
                {
                    $this->field = $field;
                    $this->post_id = $post_id;
                }

                public function get_tags()
                {
                    return [
                        'acf_hero_section_hero_h1' => [
                            'field' => $this->field,
                            'parent' => [
                                'key' => 'field_technologies_hero_section',
                                'name' => 'hero_section',
                                'type' => 'group',
                            ],
                            'duplicate' => true,
                        ],
                    ];
                }

                public function get_object_id($field, $context_object_id)
                {
                    return $this->post_id;
                }
            };

            $resolver = new \Dbvc\VisualEditor\Bricks\AcfFieldContextResolver();
            $result = $resolver->resolve(
                [
                    'expression' => '{acf_hero_section_hero_h1}',
                    'field_name' => $selector,
                ],
                [
                    'isSupported' => true,
                    'entityType' => 'post',
                    'entityId' => $post_id,
                ]
            );

            $this->assertTrue($result['ok'] ?? false);
            $this->assertSame('field_wysiwyg_hero_h1', $result['field']['key'] ?? '');
            $this->assertSame('wysiwyg', $result['field']['type'] ?? '');
            $this->assertSame('field_core_hero_section', $result['field']['parent'] ?? '');
            $this->assertSame('field_wysiwyg_hero_h1', $result['tag_object']['field']['key'] ?? '');
            $this->assertSame('wysiwyg', $result['tag_object']['field']['type'] ?? '');
        }

        public function test_wysiwyg_editor_exposes_block_format_choices(): void
        {
            $script = file_get_contents(DBVC_PLUGIN_PATH . 'addons/visual-editor/assets/js/overlay-app.js');

            $this->assertIsString($script);
            $this->assertMatchesRegularExpression("/toolbar1:\s*'formatselect bold italic bullist numlist link'/", $script);
            $this->assertStringContainsString('Paragraph=p; Heading 1=h1; Heading 2=h2;', $script);
            $this->assertStringContainsString("panelRichTextHeading6 || 'Heading 6'", $script);
            $this->assertStringContainsString("panelRichTextPreformatted || 'Preformatted'", $script);
        }
    }
}
