<?php

if (! function_exists('acf_get_field_groups')) {
    function acf_get_field_groups()
    {
        return isset($GLOBALS['dbvc_test_acf_groups']) && is_array($GLOBALS['dbvc_test_acf_groups'])
            ? $GLOBALS['dbvc_test_acf_groups']
            : [];
    }
}

if (! function_exists('acf_get_fields')) {
    function acf_get_fields($group_key)
    {
        return isset($GLOBALS['dbvc_test_acf_fields'][$group_key]) && is_array($GLOBALS['dbvc_test_acf_fields'][$group_key])
            ? $GLOBALS['dbvc_test_acf_fields'][$group_key]
            : [];
    }
}

if (! function_exists('acf_get_field_group_visibility')) {
    function acf_get_field_group_visibility($group, $screen = [])
    {
        $callback = $GLOBALS['dbvc_test_acf_group_visibility'] ?? null;

        return is_callable($callback)
            ? (bool) call_user_func($callback, $group, $screen)
            : false;
    }
}

final class VisualEditorMediaManagerR1ATest extends WP_UnitTestCase
{
    /**
     * @var array<string, mixed>
     */
    private $previous_options = [];

    /**
     * @var int
     */
    private $administrator_id = 0;

    public function set_up(): void
    {
        parent::set_up();

        foreach ([
            DBVC_Visual_Editor_Addon::OPTION_ENABLED,
            DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED,
            DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_POST_TYPES,
            DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_TAXONOMIES,
            DBVC_Visual_Editor_Addon::OPTION_SETTINGS_VERSION,
        ] as $option_name) {
            $this->previous_options[$option_name] = get_option($option_name, '__dbvc_missing_option__');
        }

        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED, '0');
        update_option(DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_POST_TYPES, '');
        update_option(DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_TAXONOMIES, '');

        register_post_type('dbvc_mm_public', [
            'label' => 'Media Manager Public',
            'public' => true,
            'show_ui' => true,
            'supports' => ['title', 'thumbnail'],
        ]);
        register_post_type('dbvc_mm_private', [
            'label' => 'Media Manager Private',
            'public' => false,
            'show_ui' => true,
            'supports' => ['title', 'thumbnail'],
        ]);
        register_taxonomy('dbvc_mm_public_tax', ['dbvc_mm_public'], [
            'label' => 'Media Manager Public Terms',
            'public' => true,
            'show_ui' => true,
        ]);
        register_taxonomy('dbvc_mm_private_tax', ['dbvc_mm_public'], [
            'label' => 'Media Manager Private Terms',
            'public' => false,
            'show_ui' => true,
        ]);

        $this->administrator_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->administrator_id);
    }

    public function tear_down(): void
    {
        unset(
            $GLOBALS['dbvc_test_acf_groups'],
            $GLOBALS['dbvc_test_acf_fields'],
            $GLOBALS['dbvc_test_acf_group_visibility'],
            $GLOBALS['dbvc_test_acf_visibility_screens']
        );

        if (taxonomy_exists('dbvc_mm_public_tax')) {
            unregister_taxonomy('dbvc_mm_public_tax');
        }
        if (taxonomy_exists('dbvc_mm_private_tax')) {
            unregister_taxonomy('dbvc_mm_private_tax');
        }
        if (post_type_exists('dbvc_mm_public')) {
            unregister_post_type('dbvc_mm_public');
        }
        if (post_type_exists('dbvc_mm_private')) {
            unregister_post_type('dbvc_mm_private');
        }

        foreach ($this->previous_options as $option_name => $value) {
            if ($value === '__dbvc_missing_option__') {
                delete_option($option_name);
            } else {
                update_option($option_name, $value);
            }
        }

        DBVC_Visual_Editor_Addon::refresh_runtime_registration();
        parent::tear_down();
    }

    public function test_media_manager_setting_is_default_off_and_requires_visual_editor(): void
    {
        delete_option(DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED);
        update_option(DBVC_Visual_Editor_Addon::OPTION_SETTINGS_VERSION, '3');
        DBVC_Visual_Editor_Addon::ensure_defaults();

        $this->assertSame('0', get_option(DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED));
        // R3-B added OPTION_CONTROL_CENTER_ENABLED to ensure_defaults(), bumping SETTINGS_VERSION 5 → 6;
        // R6-D-1 added OPTION_WORKSPACE_ENABLED, bumping 6 → 7; R5.later-perf-a added the
        // Bricks ACF tag-index switch + verification marker, bumping 7 → 8; R5.later-perf-b 8 → 9.
        $this->assertSame('9', get_option(DBVC_Visual_Editor_Addon::OPTION_SETTINGS_VERSION));
        $this->assertFalse(DBVC_Visual_Editor_Addon::is_media_manager_enabled());

        update_option(DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '0');
        $this->assertFalse(DBVC_Visual_Editor_Addon::is_media_manager_enabled());

        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        $this->assertTrue(DBVC_Visual_Editor_Addon::is_media_manager_enabled());

        $groups = DBVC_Visual_Editor_Addon::get_settings_groups();
        $meta = DBVC_Visual_Editor_Addon::get_field_meta();
        $this->assertContains(DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED, $groups['media_manager']['fields']);
        $this->assertSame('checkbox', $meta[DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED]['input']);
    }

    public function test_post_and_term_eligibility_fail_closed_for_status_visibility_exclusion_and_permission(): void
    {
        $policy = $this->policy();
        $published_id = self::factory()->post->create([
            'post_type' => 'dbvc_mm_public',
            'post_status' => 'publish',
        ]);
        $draft_id = self::factory()->post->create([
            'post_type' => 'dbvc_mm_public',
            'post_status' => 'draft',
        ]);
        $private_type_id = self::factory()->post->create([
            'post_type' => 'dbvc_mm_private',
            'post_status' => 'publish',
        ]);
        $term = self::factory()->term->create_and_get([
            'taxonomy' => 'dbvc_mm_public_tax',
            'name' => 'Public media term',
        ]);
        $private_term = self::factory()->term->create_and_get([
            'taxonomy' => 'dbvc_mm_private_tax',
            'name' => 'Private media term',
        ]);

        $published = $policy->assessPost($published_id);
        $this->assertTrue($published['eligible']);
        $this->assertTrue($published['featured_image_supported']);
        $GLOBALS['post'] = get_post($published_id);
        $this->assertSame('invalid_post', $policy->assessPost(0)['reason']);
        $this->assertContains('dbvc_mm_public', $policy->getEligiblePostTypes());
        $this->assertSame('post_not_published', $policy->assessPost($draft_id)['reason']);
        $this->assertSame('post_type_not_public', $policy->assessPost($private_type_id)['reason']);

        $this->assertTrue($policy->assessTerm($term)['eligible']);
        $this->assertContains('dbvc_mm_public_tax', $policy->getEligibleTaxonomies());
        $this->assertSame('taxonomy_not_public', $policy->assessTerm($private_term)['reason']);

        update_option(DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_POST_TYPES, 'dbvc_mm_public');
        update_option(DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_TAXONOMIES, 'dbvc_mm_public_tax');
        $this->assertSame('post_type_excluded', $policy->assessPost($published_id)['reason']);
        $this->assertSame('taxonomy_excluded', $policy->assessTerm($term)['reason']);

        update_option(DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_POST_TYPES, '');
        update_option(DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_TAXONOMIES, '');
        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber_id);
        $this->assertSame('post_not_editable', $policy->assessPost($published_id)['reason']);
        $this->assertSame('term_not_editable', $policy->assessTerm($term)['reason']);
    }

    public function test_acf_catalog_uses_exact_visibility_and_only_supports_unconditional_direct_or_group_paths(): void
    {
        $post_id = self::factory()->post->create([
            'post_type' => 'dbvc_mm_public',
            'post_status' => 'publish',
        ]);
        $this->seedAcfCatalogFixtures();

        $catalog = new \Dbvc\VisualEditor\MediaManager\AcfMediaFieldCatalog($this->policy());
        $result = $catalog->forPost($post_id);

        $this->assertTrue($result['available']);
        $this->assertTrue($result['eligible']);
        $this->assertSame('cataloged', $result['reason']);
        $this->assertSame($post_id, $result['screen']['post_id']);
        $this->assertSame('dbvc_mm_public', $result['screen']['post_type']);
        $this->assertSame(3, $result['counts']['supported_fields']);
        $this->assertSame(2, $result['counts']['image_fields']);
        $this->assertSame(1, $result['counts']['gallery_fields']);
        $this->assertSame(1, $result['counts']['top_level_fields']);
        $this->assertSame(2, $result['counts']['group_fields']);
        $this->assertSame(1, $result['counts']['conditional_fields']);
        $this->assertSame(1, $result['counts']['repeater_fields']);
        $this->assertSame(1, $result['counts']['flexible_content_fields']);
        $this->assertSame(1, $result['counts']['unsupported_nested_fields']);
        $this->assertSame(1, $result['counts']['clone_fields']);
        $this->assertSame(1, $result['counts']['applicable_groups']);
        $this->assertSame(1, $result['counts']['inapplicable_groups']);
        $this->assertSame(1, $result['counts']['inactive_groups']);

        $this->assertSame(
            ['hero_image', 'mediaGroupGallery', 'nestedGroupImage'],
            array_column($result['fields'], 'field_name')
        );
        $this->assertSame('field_media_group', $result['fields'][1]['selector']);
        $this->assertSame(['media_group'], $result['fields'][1]['group_path']);
        $this->assertSame(['media_group', 'nested_group'], $result['fields'][2]['group_path']);

        $catalog->forPost($post_id);
        $catalog_reflection = new ReflectionClass($catalog);
        $field_groups = $catalog_reflection->getProperty('field_groups');
        $field_groups->setAccessible(true);
        $fields_by_group = $catalog_reflection->getProperty('fields_by_group');
        $fields_by_group->setAccessible(true);
        $this->assertCount(3, $field_groups->getValue($catalog));
        $this->assertArrayHasKey('group_media_visible', $fields_by_group->getValue($catalog));
    }

    public function test_term_catalog_passes_term_screen_and_uses_canonical_acf_owner_id(): void
    {
        $term = self::factory()->term->create_and_get([
            'taxonomy' => 'dbvc_mm_public_tax',
            'name' => 'Catalog term',
        ]);
        $this->seedAcfCatalogFixtures();

        $catalog = new \Dbvc\VisualEditor\MediaManager\AcfMediaFieldCatalog($this->policy());
        $result = $catalog->forTerm($term);

        $this->assertTrue($result['eligible']);
        $this->assertSame($term->term_id, $result['screen']['term_id']);
        $this->assertSame('dbvc_mm_public_tax', $result['screen']['taxonomy']);
        $this->assertSame('dbvc_mm_public_tax_' . $term->term_id, $result['owner']['acf_object_id']);
    }

    public function test_media_assignment_classifier_preserves_invalid_nonempty_values(): void
    {
        $classifier = new \Dbvc\VisualEditor\MediaManager\MediaAssignmentValueClassifier();

        foreach ([null, false, '', 0, '0', []] as $empty_value) {
            $this->assertSame('empty', $classifier->classifyImage($empty_value));
            $this->assertSame('empty', $classifier->classifyGallery($empty_value));
        }

        $this->assertSame('assigned', $classifier->classifyImage(['ID' => 42]));
        $this->assertSame('assigned', $classifier->classifyGallery([42, ['id' => 43]]));
        $this->assertSame('invalid_nonempty', $classifier->classifyImage('legacy-image-url.jpg'));
        $this->assertSame('invalid_nonempty', $classifier->classifyGallery([42, 'broken']));
        $this->assertSame('invalid_nonempty', $classifier->classifyGallery(['broken']));
    }

    /**
     * @return \Dbvc\VisualEditor\MediaManager\EligibilityPolicy
     */
    private function policy()
    {
        return new \Dbvc\VisualEditor\MediaManager\EligibilityPolicy(
            new \Dbvc\VisualEditor\Permissions\CapabilityManager()
        );
    }

    /**
     * @return void
     */
    private function seedAcfCatalogFixtures(): void
    {
        $GLOBALS['dbvc_test_acf_groups'] = [
            ['key' => 'group_media_visible', 'title' => 'Visible Media', 'active' => true],
            ['key' => 'group_media_hidden', 'title' => 'Hidden Media', 'active' => true],
            ['key' => 'group_media_inactive', 'title' => 'Inactive Media', 'active' => false],
        ];
        $GLOBALS['dbvc_test_acf_fields'] = [
            'group_media_visible' => [
                ['key' => 'field_hero_image', 'name' => 'hero_image', 'label' => 'Hero image', 'type' => 'image'],
                [
                    'key' => 'field_media_group',
                    'name' => 'media_group',
                    'label' => 'Media group',
                    'type' => 'group',
                    'sub_fields' => [
                        ['key' => 'field_group_gallery', 'name' => 'mediaGroupGallery', 'label' => 'Group gallery', 'type' => 'gallery'],
                        [
                            'key' => 'field_nested_group',
                            'name' => 'nested_group',
                            'label' => 'Nested group',
                            'type' => 'group',
                            'sub_fields' => [
                                ['key' => 'field_nested_image', 'name' => 'nestedGroupImage', 'label' => 'Nested image', 'type' => 'image'],
                            ],
                        ],
                    ],
                ],
                [
                    'key' => 'field_conditional_image',
                    'name' => 'conditional_image',
                    'label' => 'Conditional image',
                    'type' => 'image',
                    'conditional_logic' => [[['field' => 'field_toggle', 'operator' => '==', 'value' => '1']]],
                ],
                [
                    'key' => 'field_repeater',
                    'name' => 'media_repeater',
                    'label' => 'Media repeater',
                    'type' => 'repeater',
                    'sub_fields' => [
                        ['key' => 'field_repeater_image', 'name' => 'repeater_image', 'label' => 'Repeater image', 'type' => 'image'],
                    ],
                ],
                [
                    'key' => 'field_flexible',
                    'name' => 'media_flexible',
                    'label' => 'Media flexible',
                    'type' => 'flexible_content',
                    'layouts' => [
                        [
                            'key' => 'layout_media',
                            'name' => 'media_layout',
                            'sub_fields' => [
                                ['key' => 'field_flexible_gallery', 'name' => 'flexible_gallery', 'label' => 'Flexible gallery', 'type' => 'gallery'],
                            ],
                        ],
                    ],
                ],
                [
                    'key' => 'field_clone_like',
                    'name' => 'clone_like',
                    'label' => 'Clone-like field',
                    'type' => 'clone',
                    'sub_fields' => [
                        ['key' => 'field_clone_image', 'name' => 'clone_image', 'label' => 'Clone image', 'type' => 'image'],
                    ],
                ],
                ['key' => 'field_caption', 'name' => 'caption', 'label' => 'Caption', 'type' => 'text'],
            ],
            'group_media_hidden' => [
                ['key' => 'field_hidden_image', 'name' => 'hidden_image', 'label' => 'Hidden image', 'type' => 'image'],
            ],
        ];
        $GLOBALS['dbvc_test_acf_group_visibility'] = static function ($group, $screen) {
            $GLOBALS['dbvc_test_acf_visibility_screens'][] = $screen;

            return ($group['key'] ?? '') === 'group_media_visible';
        };
    }
}
