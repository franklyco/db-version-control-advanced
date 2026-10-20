<?php

/**
 * R3-BX — FieldCandidateProvider (curation candidate enumeration).
 *
 * Verifies the options-only enumeration contract: only field groups whose
 * location rules resolve to an options page contribute rows; non-options
 * groups are skipped entirely; tabs/messages/accordions are skipped as
 * pseudo-fields but their labels flow into `ancestor_labels`; groups
 * (`type=group`) descend into `sub_fields`; repeaters/flex/clone are
 * emitted but NOT descended into. All coverage runs against fixture
 * arrays passed through the constructor callables — no ACF dependency.
 */
final class VisualEditorCurationCandidateProviderTest extends WP_UnitTestCase
{
    public function test_only_options_page_field_groups_produce_candidates(): void
    {
        $groups = [
            $this->makeGroup('group_options', 'Site Settings', [
                [['param' => 'options_page', 'operator' => '==', 'value' => 'site-settings']],
            ]),
            $this->makeGroup('group_post', 'Post Body Options', [
                [['param' => 'post_type', 'operator' => '==', 'value' => 'page']],
            ]),
        ];
        $fields_by_group = [
            'group_options' => [
                ['name' => 'business_name', 'label' => 'Business Name', 'type' => 'text', 'key' => 'field_b1'],
            ],
            'group_post' => [
                ['name' => 'sidebar_hide', 'label' => 'Hide Sidebar', 'type' => 'true_false', 'key' => 'field_p1'],
            ],
        ];

        $provider = $this->makeProvider($groups, $fields_by_group);
        $records = $provider->getCandidates();

        $this->assertCount(1, $records);
        $this->assertSame('option:site-settings:business_name', $records[0]['id']);
        $this->assertSame('site-settings', $records[0]['options_page']);
        $this->assertSame('text', $records[0]['field_type']);
        $this->assertSame('Business Name', $records[0]['field_label']);
    }

    public function test_group_containers_are_not_emitted_but_their_leaves_are_walked_with_ancestor_labels(): void
    {
        $groups = [
            $this->makeGroup('group_adv', 'Site Settings Advanced', [
                [['param' => 'options_page', 'operator' => '==', 'value' => 'advanced-settings']],
            ]),
        ];
        $fields_by_group = [
            'group_adv' => [
                ['type' => 'tab', 'label' => 'Brand & Design Tokens'],
                [
                    'name' => 'brand_color_palette',
                    'label' => 'Brand Colors',
                    'type' => 'group',
                    'key' => 'field_bcp',
                    'sub_fields' => [
                        [
                            'name' => 'vertical_global_palette',
                            'label' => 'Brand Colors',
                            'type' => 'group',
                            'key' => 'field_vgp',
                            'sub_fields' => [
                                ['name' => 'colorPrimary', 'label' => 'Primary', 'type' => 'color_picker', 'key' => 'field_cp'],
                                ['name' => 'colorSecondary', 'label' => 'Secondary', 'type' => 'color_picker', 'key' => 'field_cs'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $records = $this->makeProvider($groups, $fields_by_group)->getCandidates();

        // The two nested groups must NOT appear as candidate rows — they are
        // structural containers with no editor / no descriptor / no R5 slice.
        $types = array_column($records, 'field_type');
        $this->assertNotContains('group', $types, 'ACF group containers must be skipped from the candidate list');

        // Only the two color_picker leaves survive as rows.
        $this->assertCount(2, $records);
        $ids = array_column($records, 'id');
        sort($ids);
        $this->assertSame(
            [
                'option:advanced-settings:brand_color_palette>vertical_global_palette>colorPrimary',
                'option:advanced-settings:brand_color_palette>vertical_global_palette>colorSecondary',
            ],
            $ids
        );

        // Ancestor labels still flow from the nearest preceding tab into each leaf.
        $primary = array_values(array_filter($records, static function ($r) {
            return $r['field_name']=== 'colorPrimary';
        }))[0];
        $this->assertSame(['Brand & Design Tokens'], $primary['ancestor_labels']);
        $this->assertFalse($primary['is_repeater_family']);
    }

    public function test_a_group_with_no_leaves_contributes_zero_candidates(): void
    {
        // Groups nested three deep whose only content is more groups — no leaves
        // anywhere in the tree — must contribute zero candidate rows.
        $groups = [
            $this->makeGroup('group_hollow', 'Hollow', [
                [['param' => 'options_page', 'operator' => '==', 'value' => 'site-settings']],
            ]),
        ];
        $fields_by_group = [
            'group_hollow' => [
                [
                    'name' => 'outer',
                    'label' => 'Outer',
                    'type' => 'group',
                    'key' => 'field_outer',
                    'sub_fields' => [
                        [
                            'name' => 'inner',
                            'label' => 'Inner',
                            'type' => 'group',
                            'key' => 'field_inner',
                            'sub_fields' => [
                                // no leaves — every child is itself a group
                                [
                                    'name' => 'innermost',
                                    'label' => 'Innermost',
                                    'type' => 'group',
                                    'key' => 'field_innermost',
                                    'sub_fields' => [],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $records = $this->makeProvider($groups, $fields_by_group)->getCandidates();
        $this->assertSame([], $records);
    }

    public function test_repeaters_flexible_content_and_clones_are_emitted_but_children_are_not_descended(): void
    {
        $groups = [
            $this->makeGroup('group_a', 'Site Settings', [
                [['param' => 'options_page', 'operator' => '==', 'value' => 'site-settings']],
            ]),
        ];
        $fields_by_group = [
            'group_a' => [
                [
                    'name' => 'hours',
                    'label' => 'Hours',
                    'type' => 'repeater',
                    'key' => 'field_hours',
                    'sub_fields' => [
                        ['name' => 'day', 'label' => 'Day', 'type' => 'text', 'key' => 'field_day'],
                    ],
                ],
                [
                    'name' => 'popup_variants',
                    'label' => 'Popup Variants',
                    'type' => 'flexible_content',
                    'key' => 'field_pv',
                    'layouts' => [
                        [
                            'name' => 'basic',
                            'sub_fields' => [
                                ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'key' => 'field_ptitle'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $records = $this->makeProvider($groups, $fields_by_group)->getCandidates();

        $ids = array_column($records, 'id');
        sort($ids);
        $this->assertSame(
            [
                'option:site-settings:hours',
                'option:site-settings:popup_variants',
            ],
            $ids
        );
        // Neither the repeater `day` subfield nor any flexible layout subfield became a candidate.
        foreach ($records as $r) {
            $this->assertTrue($r['is_repeater_family'], 'Every emitted row must be marked as repeater-family for this fixture');
        }
    }

    public function test_message_and_accordion_pseudo_fields_are_skipped(): void
    {
        $groups = [
            $this->makeGroup('group_msg', 'Site Settings', [
                [['param' => 'options_page', 'operator' => '==', 'value' => 'site-settings']],
            ]),
        ];
        $fields_by_group = [
            'group_msg' => [
                ['type' => 'message', 'label' => 'Note'],
                ['type' => 'accordion', 'label' => 'Section'],
                ['name' => 'tagline', 'label' => 'Tagline', 'type' => 'text', 'key' => 'field_tag'],
            ],
        ];

        $records = $this->makeProvider($groups, $fields_by_group)->getCandidates();
        $this->assertCount(1, $records);
        $this->assertSame('tagline', $records[0]['field_name']);
    }

    public function test_records_are_sorted_deterministically_by_options_page_then_name_path(): void
    {
        $groups = [
            $this->makeGroup('group_z', 'Z', [
                [['param' => 'options_page', 'operator' => '==', 'value' => 'z-settings']],
            ]),
            $this->makeGroup('group_a', 'A', [
                [['param' => 'options_page', 'operator' => '==', 'value' => 'a-settings']],
            ]),
        ];
        $fields_by_group = [
            'group_z' => [
                ['name' => 'aaa', 'label' => 'Aaa', 'type' => 'text', 'key' => 'field_zaaa'],
            ],
            'group_a' => [
                ['name' => 'zzz', 'label' => 'Zzz', 'type' => 'text', 'key' => 'field_azzz'],
                ['name' => 'aaa', 'label' => 'Aaa', 'type' => 'text', 'key' => 'field_aaaa'],
            ],
        ];

        $records = $this->makeProvider($groups, $fields_by_group)->getCandidates();
        $ids = array_column($records, 'id');
        $this->assertSame(
            [
                'option:a-settings:aaa',
                'option:a-settings:zzz',
                'option:z-settings:aaa',
            ],
            $ids
        );
    }

    /**
     * @param string                                   $key
     * @param string                                   $title
     * @param array<int, array<int, array<string, mixed>>> $location
     * @return array<string, mixed>
     */
    private function makeGroup($key, $title, array $location)
    {
        return ['key' => $key, 'title' => $title, 'location' => $location];
    }

    /**
     * @param array<int, array<string, mixed>>                 $groups
     * @param array<string, array<int, array<string, mixed>>>  $fields_by_group
     * @return \Dbvc\VisualEditor\Curation\FieldCandidateProvider
     */
    private function makeProvider(array $groups, array $fields_by_group)
    {
        return new \Dbvc\VisualEditor\Curation\FieldCandidateProvider(
            static function () use ($groups) {
                return $groups;
            },
            static function (array $group) use ($fields_by_group) {
                $key = isset($group['key']) ? (string) $group['key'] : '';

                return isset($fields_by_group[$key]) ? $fields_by_group[$key] : [];
            }
        );
    }
}
