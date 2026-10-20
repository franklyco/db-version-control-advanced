<?php

/**
 * R3-A — Provider-agnostic control registry.
 *
 * Verifies the discovery-side contract (Slice R3-A): a provider registers,
 * malformed and duplicate records are rejected observably, per-user visibility
 * filters at list time, and the safe list projection carries no `source` bag
 * (that stays internal). Zero write-authority impact — the registry is a
 * read-only discovery surface.
 */
final class VisualEditorControlRegistryTest extends WP_UnitTestCase
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private $observed = [];

    public function set_up(): void
    {
        parent::set_up();
        $this->observed = [];
        add_action('dbvc_visual_editor_control_registry_invalid', function ($reason, $context) {
            $this->observed[] = ['reason' => $reason, 'context' => $context];
        }, 10, 2);
    }

    public function tear_down(): void
    {
        remove_all_actions('dbvc_visual_editor_control_registry_invalid');
        parent::tear_down();
    }

    public function test_register_and_list_a_single_provider_with_two_controls(): void
    {
        $registry = new \Dbvc\VisualEditor\Registry\ControlRegistry();
        $this->assertTrue($registry->registerProvider($this->makeProvider('shared_globals', [
            ['id' => 'default_posts', 'label' => 'Default Posts', 'category' => 'globals', 'fieldFamily' => 'relationship'],
            ['id' => 'default_terms', 'label' => 'Default Terms', 'category' => 'globals', 'fieldFamily' => 'relationship'],
        ])));

        $this->assertSame(['shared_globals'], $registry->providerIds());
        $items = $registry->listControls();
        $this->assertCount(2, $items);
        $this->assertSame('shared_globals:default_posts', $items[0]['publicId']);
        $this->assertSame('Default Posts', $items[0]['label']);
        $this->assertSame('globals', $items[0]['category']);
        $this->assertSame('relationship', $items[0]['fieldFamily']);
        $this->assertSame('available', $items[0]['status']);
        // The safe list projection must NOT include the provider `source` hint.
        $this->assertArrayNotHasKey('source', $items[0]);
    }

    public function test_duplicate_provider_id_is_rejected_and_observed(): void
    {
        $registry = new \Dbvc\VisualEditor\Registry\ControlRegistry();
        $this->assertTrue($registry->registerProvider($this->makeProvider('brand', [])));
        $this->assertFalse($registry->registerProvider($this->makeProvider('brand', [])));

        $this->assertSame(['brand'], $registry->providerIds());
        $this->assertCount(1, $this->observed);
        $this->assertSame('provider_duplicate', $this->observed[0]['reason']);
        $this->assertSame('brand', $this->observed[0]['context']['provider_id']);
    }

    public function test_provider_with_invalid_id_is_rejected(): void
    {
        $registry = new \Dbvc\VisualEditor\Registry\ControlRegistry();
        $this->assertFalse($registry->registerProvider($this->makeProvider('', [])));

        $this->assertSame([], $registry->providerIds());
        $this->assertSame('provider_invalid_id', $this->observed[0]['reason']);
    }

    public function test_duplicate_local_id_within_same_provider_is_rejected(): void
    {
        $registry = new \Dbvc\VisualEditor\Registry\ControlRegistry();
        $registry->registerProvider($this->makeProvider('brand', [
            ['id' => 'palette', 'label' => 'Palette'],
            ['id' => 'palette', 'label' => 'Duplicate Palette'],
            ['id' => 'typography', 'label' => 'Typography'],
        ]));

        $items = $registry->listControls();
        $this->assertCount(2, $items);
        $public_ids = array_column($items, 'publicId');
        $this->assertContains('brand:palette', $public_ids);
        $this->assertContains('brand:typography', $public_ids);

        $reasons = array_column($this->observed, 'reason');
        $this->assertContains('record_duplicate_local_id', $reasons);
    }

    public function test_same_local_id_across_two_providers_coexists_under_distinct_public_ids(): void
    {
        $registry = new \Dbvc\VisualEditor\Registry\ControlRegistry();
        $registry->registerProvider($this->makeProvider('brand', [
            ['id' => 'palette', 'label' => 'Brand Palette'],
        ]));
        $registry->registerProvider($this->makeProvider('theme', [
            ['id' => 'palette', 'label' => 'Theme Palette'],
        ]));

        $items = $registry->listControls();
        $this->assertCount(2, $items);
        $public_ids = array_column($items, 'publicId');
        sort($public_ids);
        $this->assertSame(['brand:palette', 'theme:palette'], $public_ids);
    }

    public function test_malformed_record_is_skipped_and_observed(): void
    {
        $registry = new \Dbvc\VisualEditor\Registry\ControlRegistry();
        $registry->registerProvider($this->makeProvider('shared_globals', [
            ['id' => '', 'label' => 'No id'],
            ['id' => 'ok', 'label' => ''],
            ['id' => 'good', 'label' => 'Good'],
            'not-even-an-array',
        ]));

        $items = $registry->listControls();
        $this->assertCount(1, $items);
        $this->assertSame('shared_globals:good', $items[0]['publicId']);

        $invalid = array_filter($this->observed, static function ($e) {
            return $e['reason'] === 'record_invalid';
        });
        $this->assertCount(3, $invalid);
    }

    public function test_visibility_closure_filters_per_current_user(): void
    {
        $registry = new \Dbvc\VisualEditor\Registry\ControlRegistry();
        $registry->registerProvider($this->makeProvider('shared_globals', [
            ['id' => 'always', 'label' => 'Always visible'],
            [
                'id' => 'admins_only',
                'label' => 'Admins only',
                'visibleTo' => static function () {
                    return current_user_can('manage_options');
                },
            ],
        ]));

        // A subscriber sees only the always-visible entry.
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $items = $registry->listControls();
        $this->assertCount(1, $items);
        $this->assertSame('shared_globals:always', $items[0]['publicId']);

        // An administrator sees both.
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $items = $registry->listControls();
        $this->assertCount(2, $items);
    }

    public function test_category_and_status_filters_prune_before_visibility(): void
    {
        $registry = new \Dbvc\VisualEditor\Registry\ControlRegistry();
        $registry->registerProvider($this->makeProvider('brand', [
            ['id' => 'palette', 'label' => 'Palette', 'category' => 'branding', 'status' => 'available'],
            ['id' => 'legacy', 'label' => 'Legacy', 'category' => 'branding', 'status' => 'unsupported'],
            ['id' => 'font', 'label' => 'Font', 'category' => 'typography', 'status' => 'available'],
        ]));

        $branding = $registry->listControls(['category' => 'branding']);
        $this->assertCount(2, $branding);
        $branding_ids = array_column($branding, 'publicId');
        sort($branding_ids);
        $this->assertSame(['brand:legacy', 'brand:palette'], $branding_ids);

        $available = $registry->listControls(['status' => 'available']);
        $this->assertCount(2, $available);
    }

    public function test_normalization_defaults_unknown_values_to_safe_choices(): void
    {
        $registry = new \Dbvc\VisualEditor\Registry\ControlRegistry();
        $registry->registerProvider($this->makeProvider('brand', [
            [
                'id' => 'weird',
                'label' => 'Weird',
                'ownerType' => 'martian',
                'fieldFamily' => 'starmap',
                'status' => 'unknown_status',
            ],
        ]));

        $items = $registry->listControls();
        $this->assertCount(1, $items);
        // ownerType `martian` → default `option`; fieldFamily `starmap` → `other`;
        // status `unknown_status` → `unavailable` (fail-safe: not surfaced as available).
        $this->assertSame('option', $items[0]['ownerType']);
        $this->assertSame('other', $items[0]['fieldFamily']);
        $this->assertSame('unavailable', $items[0]['status']);
    }

    public function test_empty_registry_lists_nothing(): void
    {
        $registry = new \Dbvc\VisualEditor\Registry\ControlRegistry();
        $this->assertSame([], $registry->listControls());
        $this->assertSame([], $registry->providerIds());
    }

    public function test_get_visible_record_resolves_and_fails_closed(): void
    {
        $registry = new \Dbvc\VisualEditor\Registry\ControlRegistry();
        $registry->registerProvider($this->makeProvider('brand', [
            ['id' => 'palette', 'label' => 'Palette', 'source' => ['optionKey' => 'palette_map']],
            [
                'id' => 'admins_only',
                'label' => 'Admins only',
                'visibleTo' => static function () { return current_user_can('manage_options'); },
            ],
        ]));

        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $palette = $registry->getVisibleRecord('brand:palette');
        $this->assertNotNull($palette);
        // Internal record CARRIES the source bag — this method is the internal
        // bridge R3-C uses to hand the provider back its own hint at open time.
        $this->assertSame(['optionKey' => 'palette_map'], $palette->source);

        // Visibility-blocked record fails closed.
        $this->assertNull($registry->getVisibleRecord('brand:admins_only'));
        // Unknown record fails closed.
        $this->assertNull($registry->getVisibleRecord('brand:no_such_thing'));
        // Malformed public id fails closed.
        $this->assertNull($registry->getVisibleRecord('not-a-valid-public-id'));
    }

    // ---------------------------------------------------------------------
    // R5.later-perf-c (E-162 F2) — per-request memo of the record pass
    // ---------------------------------------------------------------------

    public function test_perf_c_record_pass_runs_once_per_instance_across_list_and_lookup(): void
    {
        $registry = new \Dbvc\VisualEditor\Registry\ControlRegistry();
        $provider = $this->makeProvider('brand', [
            ['id' => 'alpha', 'label' => 'Alpha'],
            ['id' => 'beta', 'label' => 'Beta'],
        ]);
        $registry->registerProvider($provider);

        $registry->listControls();
        $registry->listControls(['q' => 'alp']);
        $alpha = $registry->getVisibleRecord('brand:alpha');
        $beta = $registry->getVisibleRecord('brand:beta');
        $this->assertNull($registry->getVisibleRecord('brand:gamma'));

        // Before perf-c every one of those five reads called getControls().
        $this->assertSame(1, $provider->calls);
        $this->assertNotNull($alpha);
        $this->assertNotNull($beta);
        // The lookup hands back the memoised record, not a copy.
        $this->assertSame($alpha, $registry->getVisibleRecord('brand:alpha'));
        $this->assertSame(1, $provider->calls);
    }

    public function test_perf_c_memo_is_invalidated_by_registration_and_explicit_reset(): void
    {
        $registry = new \Dbvc\VisualEditor\Registry\ControlRegistry();
        $first = $this->makeProvider('brand', [['id' => 'alpha', 'label' => 'Alpha']]);
        $registry->registerProvider($first);
        $this->assertCount(1, $registry->listControls());
        $this->assertSame(1, $first->calls);

        // A provider registered after the first read must show up: the memo
        // is dropped on registration, so both providers are asked again.
        $second = $this->makeProvider('theme', [['id' => 'omega', 'label' => 'Omega']]);
        $registry->registerProvider($second);
        $this->assertCount(2, $registry->listControls());
        $this->assertSame(2, $first->calls);
        $this->assertSame(1, $second->calls);

        // A rejected duplicate registration does not invalidate.
        $this->assertFalse($registry->registerProvider($this->makeProvider('brand', [])));
        $registry->listControls();
        $this->assertSame(2, $first->calls);

        // Explicit reset forces a fresh pass.
        $registry->resetRecordCache();
        $this->assertNotNull($registry->getVisibleRecord('theme:omega'));
        $this->assertSame(3, $first->calls);
        $this->assertSame(2, $second->calls);
    }

    public function test_perf_c_visibility_is_still_evaluated_per_read_on_the_memoised_pass(): void
    {
        $registry = new \Dbvc\VisualEditor\Registry\ControlRegistry();
        $provider = $this->makeProvider('brand', [
            ['id' => 'everyone', 'label' => 'Everyone'],
            [
                'id' => 'admins_only',
                'label' => 'Admins only',
                'visibleTo' => static function () { return current_user_can('manage_options'); },
            ],
        ]);
        $registry->registerProvider($provider);

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertCount(2, $registry->listControls());
        $this->assertNotNull($registry->getVisibleRecord('brand:admins_only'));

        // Same instance, different user: the memoised pass is reused (no new
        // getControls() call) but the capability gate re-runs and closes.
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->assertCount(1, $registry->listControls());
        $this->assertNull($registry->getVisibleRecord('brand:admins_only'));
        $this->assertSame(1, $provider->calls);
    }

    // ---------------------------------------------------------------------
    // R5.7-b — parentPublicId + tree flatten
    // ---------------------------------------------------------------------

    public function test_r57b_parent_public_id_survives_safe_list_projection(): void
    {
        $registry = new \Dbvc\VisualEditor\Registry\ControlRegistry();
        $registry->registerProvider($this->makeProvider('vertical', [
            ['id' => 'repeater_menus', 'label' => 'Menus', 'status' => 'unsupported'],
            [
                'id' => 'nav__menu_posts_row_0',
                'label' => 'Menu posts · Primary menu',
                'parentPublicId' => 'vertical:repeater_menus',
            ],
        ]));

        $items = $registry->listControls();
        $this->assertCount(2, $items);
        // Parent has empty parentPublicId (root).
        $this->assertSame('vertical:repeater_menus', $items[0]['publicId']);
        $this->assertSame('', $items[0]['parentPublicId']);
        // Child carries the link.
        $this->assertSame('vertical:nav__menu_posts_row_0', $items[1]['publicId']);
        $this->assertSame('vertical:repeater_menus', $items[1]['parentPublicId']);
    }

    public function test_r57b_children_sort_immediately_after_parent_via_dfs_flatten(): void
    {
        // Even when the sort order of the raw list would place children
        // apart from their parent (label-alpha would), the DFS flatten
        // must keep each child adjacent to its parent.
        $registry = new \Dbvc\VisualEditor\Registry\ControlRegistry();
        $registry->registerProvider($this->makeProvider('vertical', [
            // Deliberately mis-ordered to force the DFS flatten to
            // reassemble the tree.
            ['id' => 'other_top_level', 'label' => 'Zeta', 'status' => 'available'],
            [
                'id' => 'leaf_1',
                'label' => 'Menu · Row 1',
                'parentPublicId' => 'vertical:repeater_menus',
            ],
            ['id' => 'repeater_menus', 'label' => 'Menus', 'status' => 'unsupported'],
            [
                'id' => 'leaf_2',
                'label' => 'Menu · Row 2',
                'parentPublicId' => 'vertical:repeater_menus',
            ],
        ]));

        $items = $registry->listControls();
        $this->assertCount(4, $items);
        // First root by alpha is 'Menu · Row 1' ? No — 'Menus' comes before
        // 'Zeta'. Since leaf_1 and leaf_2 both link to the parent, they get
        // sorted UNDER 'Menus'. 'Zeta' is a separate root and comes after.
        $public_ids = array_map(function ($i) {
            return $i['publicId'];
        }, $items);
        // Expected order: [parent, leaf_1, leaf_2, other_top_level]
        // (parent sorts before Zeta by alpha; leaves DFS-emit right after
        // parent, in their own sortKey/label order).
        $parent_pos = array_search('vertical:repeater_menus', $public_ids, true);
        $leaf1_pos = array_search('vertical:leaf_1', $public_ids, true);
        $leaf2_pos = array_search('vertical:leaf_2', $public_ids, true);
        $other_pos = array_search('vertical:other_top_level', $public_ids, true);
        $this->assertNotFalse($parent_pos);
        $this->assertNotFalse($leaf1_pos);
        $this->assertNotFalse($leaf2_pos);
        $this->assertLessThan($leaf1_pos, $parent_pos, 'parent before leaf 1');
        $this->assertLessThan($leaf2_pos, $parent_pos, 'parent before leaf 2');
        $this->assertLessThan($other_pos, $leaf2_pos, 'children before other root');
    }

    public function test_r57b_cross_provider_parent_public_id_is_dropped(): void
    {
        // A child referencing a different provider's publicId as parent
        // MUST be treated as if it has no parent (silently dropped from the
        // field). The record still surfaces — as a top-level root.
        $registry = new \Dbvc\VisualEditor\Registry\ControlRegistry();
        $registry->registerProvider($this->makeProvider('vertical', [
            [
                'id' => 'cross_provider_child',
                'label' => 'Sneaky',
                // Parent belongs to a different provider — must be dropped.
                'parentPublicId' => 'shared_globals:some_parent',
            ],
        ]));

        $items = $registry->listControls();
        $this->assertCount(1, $items);
        $this->assertSame('vertical:cross_provider_child', $items[0]['publicId']);
        $this->assertSame('', $items[0]['parentPublicId'], 'cross-provider link dropped');
    }

    public function test_r57b_orphaned_child_falls_back_to_root_position(): void
    {
        // Child references a publicId that doesn't exist in the current
        // pass (parent was filtered out by visibility, for example). The
        // child still surfaces as a top-level orphan rather than
        // disappearing entirely.
        $registry = new \Dbvc\VisualEditor\Registry\ControlRegistry();
        $registry->registerProvider($this->makeProvider('vertical', [
            [
                'id' => 'orphan_leaf',
                'label' => 'Orphan',
                'parentPublicId' => 'vertical:missing_parent',
            ],
        ]));

        $items = $registry->listControls();
        $this->assertCount(1, $items);
        $this->assertSame('vertical:orphan_leaf', $items[0]['publicId']);
        // parentPublicId survives on the record so the drawer can log or
        // display the linkage; the flatten step just doesn't nest it.
        $this->assertSame('vertical:missing_parent', $items[0]['parentPublicId']);
    }

    public function test_r57b_meta_role_and_child_count_pass_through_safe_projection(): void
    {
        $registry = new \Dbvc\VisualEditor\Registry\ControlRegistry();
        $registry->registerProvider($this->makeProvider('vertical', [
            [
                'id' => 'repeater_menus',
                'label' => 'Menus',
                'status' => 'unsupported',
                'meta' => [
                    'badge' => 'Vertical',
                    'role' => 'repeater_parent',
                    'childCount' => 3,
                ],
            ],
        ]));

        $items = $registry->listControls();
        $this->assertCount(1, $items);
        $this->assertSame('repeater_parent', $items[0]['meta']['role']);
        $this->assertSame(3, $items[0]['meta']['childCount']);
    }

    /**
     * @param string                                     $providerId
     * @param array<int, array<string, mixed>|string>    $controls
     * @return \Dbvc\VisualEditor\Registry\ControlProvider
     */
    private function makeProvider($providerId, array $controls)
    {
        return new class ($providerId, $controls) implements \Dbvc\VisualEditor\Registry\ControlProvider {
            private $providerId;
            private $controls;
            public function __construct($providerId, array $controls)
            {
                $this->providerId = $providerId;
                $this->controls = $controls;
            }
            public function getProviderId()
            {
                return $this->providerId;
            }
            /** @var int R5.later-perf-c — how many times the registry asked. */
            public $calls = 0;
            public function getControls()
            {
                $this->calls++;
                return $this->controls;
            }
            // R3-C-1: interface widened with buildDescriptor. This anonymous R3-A
            // test provider does not need to mint real descriptors — returning
            // null is the interface's documented "record no longer resolves"
            // signal and lets these tests keep focusing on registry mechanics.
            public function buildDescriptor(\Dbvc\VisualEditor\Registry\ControlRecord $record, $sessionId, array $pageContext)
            {
                return null;
            }
            // R4-A: interface widened with buildValueSummary. Same story —
            // registry-mechanics tests don't need real per-family summaries.
            public function buildValueSummary(\Dbvc\VisualEditor\Registry\ControlRecord $record, $sessionId)
            {
                return null;
            }
        };
    }
}
