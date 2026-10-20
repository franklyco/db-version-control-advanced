<?php

// Guarded ACF shims (shared with the other Media Index / scanner tests).
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

/**
 * R2-H Phase 1 Slice 4b-2 — topology/exclusion rebuild triggers + atomic swap.
 *
 * Verifies: a rebuild keeps serving the OLD generation while it drains (reads must
 * not see a half-built index); on completion the serving pointer swaps to the fresh
 * generation and the old one is pruned; each trigger surface (ACF field-group
 * change, exclusion-option change, topology fingerprint drift) begins a rebuild; a
 * trigger fired while a rebuild is already in flight is a no-op; and the invalidator
 * dual-writes into both generations so a mid-rebuild edit survives the swap.
 */
final class VisualEditorMediaIndexRebuildTest extends WP_UnitTestCase
{
    /**
     * @var array<string, mixed>
     */
    private $previous_options = [];

    /**
     * @var \Dbvc\VisualEditor\MediaManager\MediaIndexStore
     */
    private $store;

    public function set_up(): void
    {
        parent::set_up();

        foreach ([
            DBVC_Visual_Editor_Addon::OPTION_ENABLED,
            DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED,
            DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_POST_TYPES,
            DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_TAXONOMIES,
            'dbvc_visual_editor_media_index_build',
            'dbvc_visual_editor_media_index_topology_fingerprint',
            'dbvc_visual_editor_media_index_building_generation',
        ] as $option_name) {
            $this->previous_options[$option_name] = get_option($option_name, '__dbvc_missing_option__');
        }

        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_POST_TYPES, 'post,page,attachment');
        update_option(DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_TAXONOMIES, 'category,post_tag,post_format,nav_menu,link_category');
        delete_option('dbvc_visual_editor_media_index_build');
        delete_option('dbvc_visual_editor_media_index_topology_fingerprint');
        delete_option('dbvc_visual_editor_media_index_building_generation');

        register_post_type('dbvc_rbld', [
            'label' => 'Rebuild Content',
            'public' => true,
            'show_ui' => true,
            'supports' => ['title'],
        ]);

        $this->seedAcfDefinitions();

        $this->store = new \Dbvc\VisualEditor\MediaManager\MediaIndexStore();
        $this->store->maybeUpgrade();
        $this->store->deleteAll();
        $this->store->abortRebuild();
    }

    public function tear_down(): void
    {
        $this->store->deleteAll();
        $this->store->abortRebuild();
        unset(
            $GLOBALS['dbvc_test_acf_groups'],
            $GLOBALS['dbvc_test_acf_fields'],
            $GLOBALS['dbvc_test_acf_group_visibility']
        );
        if (post_type_exists('dbvc_rbld')) {
            unregister_post_type('dbvc_rbld');
        }
        if (post_type_exists('dbvc_rbld_extra')) {
            unregister_post_type('dbvc_rbld_extra');
        }
        foreach ($this->previous_options as $option_name => $value) {
            if ($value === '__dbvc_missing_option__') {
                delete_option($option_name);
            } else {
                update_option($option_name, $value);
            }
        }

        parent::tear_down();
    }

    public function test_rebuild_keeps_serving_old_generation_until_completion(): void
    {
        $ids = $this->createPosts(4);

        // Initial build — populates the serving generation.
        $builder = $this->makeBuilder();
        $this->drainBuilder($builder);
        $serving_before = $this->store->currentGeneration();
        $this->assertSame(4, $this->store->countEntities(['generation' => $serving_before]));

        // Kick off a rebuild.
        $read_model = $this->makeReadModel();
        $before = $read_model->getList(['limit' => 100]);
        $this->assertCount(4, $before['items']);
        $this->assertSame($serving_before, $before['generation']);

        $building = $this->store->beginRebuild();
        $builder->reset();

        $this->assertTrue($this->store->hasActiveRebuild());
        $this->assertNotSame($serving_before, $building);
        $this->assertSame($building, $this->store->buildingGeneration());
        $this->assertSame($building, $this->store->activeBuildGeneration());
        // The reader must still see the old, complete generation.
        $this->assertSame($serving_before, $this->store->currentGeneration());

        // One in-flight chunk. Reads still see the OLD generation.
        $partial = $builder->runChunk(2);
        $this->assertFalse($partial['complete']);
        $this->assertSame($building, $partial['generation']);
        $mid = $read_model->getList(['limit' => 100]);
        $this->assertSame($serving_before, $mid['generation']);
        $this->assertCount(4, $mid['items'], 'Mid-rebuild reads must still see the complete OLD generation.');
        // The building generation should hold the two rows drained so far — those
        // are separate rows from the serving-gen rows (schema v2 unique key).
        $this->assertSame(2, $this->store->countEntities(['generation' => $building]));
        $this->assertSame(4, $this->store->countEntities(['generation' => $serving_before]));

        // Drain the rest. The final chunk completes the rebuild.
        $guard = 0;
        do {
            $result = $builder->runChunk(2);
            $guard++;
        } while (! $result['complete'] && $guard < 20);
        $this->assertTrue($result['complete']);

        // The serving pointer swapped and the old generation was pruned.
        $this->assertSame($building, $this->store->currentGeneration());
        $this->assertFalse($this->store->hasActiveRebuild());
        $this->assertSame(0, $this->store->countEntities(['generation' => $serving_before]));
        $this->assertSame(4, $this->store->countEntities(['generation' => $building]));

        $after = $read_model->getList(['limit' => 100]);
        $this->assertSame($building, $after['generation']);
        $this->assertCount(4, $after['items']);

        unset($ids);
    }

    public function test_each_trigger_starts_a_rebuild_and_a_concurrent_trigger_is_a_noop(): void
    {
        $this->createPosts(2);
        $builder = $this->makeBuilder();
        $this->drainBuilder($builder);
        $serving = $this->store->currentGeneration();

        $controller = $this->makeController($builder);

        // ACF field-group save/delete → rebuild.
        $controller->triggerRebuild('test_seed'); // primes the initial event
        $first_building = $this->store->buildingGeneration();
        $this->assertNotSame('', $first_building);
        $this->assertNotSame($serving, $first_building);

        // A second trigger while a rebuild is already in flight is a no-op — the
        // building generation must NOT change out from under the in-progress drain.
        $this->assertFalse($controller->triggerRebuild('second_call'));
        $this->assertSame($first_building, $this->store->buildingGeneration());

        // Drain to completion so the store returns to a "no rebuild" state.
        $this->drainBuilder($builder);
        $this->assertSame($first_building, $this->store->currentGeneration());
        $this->assertFalse($this->store->hasActiveRebuild());

        // Simulate an ACF field-group save via the hook.
        $controller->register();
        try {
            do_action('acf/update_field_group', ['key' => 'group_bld_media']);
            $this->assertTrue($this->store->hasActiveRebuild(), 'ACF field-group save must begin a rebuild.');
        } finally {
            $controller->unregister();
        }

        // Drain again.
        $this->drainBuilder($builder);

        // Simulate an exclusion-option change via update_option.
        $controller->register();
        try {
            update_option(DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_POST_TYPES, 'post,page,attachment,nav_menu_item');
            $this->assertTrue($this->store->hasActiveRebuild(), 'Exclusion-option change must begin a rebuild.');
        } finally {
            $controller->unregister();
        }
    }

    public function test_topology_fingerprint_check_triggers_rebuild_on_drift(): void
    {
        $this->createPosts(2);
        $builder = $this->makeBuilder();
        $this->drainBuilder($builder);
        $controller = $this->makeController($builder);

        // First call primes the fingerprint (no rebuild yet).
        $controller->checkTopologyFingerprint();
        $this->assertFalse($this->store->hasActiveRebuild(), 'The initial fingerprint prime must not trigger a rebuild.');
        $stored = get_option('dbvc_visual_editor_media_index_topology_fingerprint', '');
        $this->assertNotSame('', $stored);

        // No drift → no rebuild.
        $controller->checkTopologyFingerprint();
        $this->assertFalse($this->store->hasActiveRebuild(), 'A stable topology must not trigger a rebuild.');

        // Register a new public post type — the fingerprint should drift.
        register_post_type('dbvc_rbld_extra', [
            'label' => 'Extra',
            'public' => true,
            'show_ui' => true,
        ]);
        $controller->checkTopologyFingerprint();
        $this->assertTrue($this->store->hasActiveRebuild(), 'A topology drift must trigger a rebuild.');

        // Draining completes it and the fingerprint stays stable next time.
        $this->drainBuilder($builder);
        $controller->checkTopologyFingerprint();
        $this->assertFalse($this->store->hasActiveRebuild(), 'A drained + swapped rebuild must leave the store idle.');
    }

    public function test_invalidator_dual_writes_into_serving_and_building_generations(): void
    {
        // The capability-scoped invalidator only re-indexes entities the current user
        // can edit. Act as an administrator for the mid-rebuild save; the dual-write
        // path is what this test is about, not per-user filtering.
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $ids = $this->createPosts(2);
        $builder = $this->makeBuilder();
        $this->drainBuilder($builder);
        $serving = $this->store->currentGeneration();

        // Start a rebuild but do NOT drain — a save happens mid-rebuild.
        $building = $this->store->beginRebuild();
        $builder->reset();

        // Sanity: the target post has a row in the serving gen (from the initial build)
        // and no row in the building gen yet.
        $this->assertIsArray($this->store->getEntity('post', $ids[0], 'dbvc_rbld'));
        $this->assertSame(0, $this->store->countEntities(['generation' => $building]));

        // A mid-rebuild edit runs through the invalidator. It writes to serving AND to
        // building so the swap does not lose the change.
        $invalidator = $this->makeInvalidator();
        $invalidator->reindexEntity('post', 'dbvc_rbld', $ids[0]);

        global $wpdb;
        $serving_rows = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}dbvc_ve_media_field_index WHERE entity_type = %s AND entity_id = %d AND index_generation = %s",
                'post',
                $ids[0],
                $serving
            )
        );
        $building_rows = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}dbvc_ve_media_field_index WHERE entity_type = %s AND entity_id = %d AND index_generation = %s",
                'post',
                $ids[0],
                $building
            )
        );
        $this->assertSame(1, $serving_rows, 'Invalidator must keep the serving-gen row current.');
        $this->assertSame(1, $building_rows, 'Invalidator must dual-write into the building-gen row.');

        // Drain the rest of the rebuild. Post-swap the row for the invalidated entity
        // survives because it was already written into the building generation.
        $this->drainBuilder($builder);
        $this->assertSame($building, $this->store->currentGeneration());
        $this->assertIsArray($this->store->getEntity('post', $ids[0], 'dbvc_rbld'));
    }

    /**
     * @param int $count
     * @return array<int, int>
     */
    private function createPosts($count)
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = self::factory()->post->create([
                'post_type' => 'dbvc_rbld',
                'post_status' => 'publish',
                'post_title' => 'Rebuild ' . $i,
            ]);
        }

        return $ids;
    }

    /**
     * @param \Dbvc\VisualEditor\MediaManager\MediaIndexBuilder $builder
     * @return void
     */
    private function drainBuilder($builder)
    {
        $guard = 0;
        do {
            $result = $builder->runChunk(50);
            $guard++;
        } while (! $result['complete'] && $guard < 50);
    }

    /**
     * @return \Dbvc\VisualEditor\MediaManager\MediaIndexReadModel
     */
    private function makeReadModel()
    {
        $capabilities = new \Dbvc\VisualEditor\Permissions\CapabilityManager();
        $policy = new \Dbvc\VisualEditor\MediaManager\EligibilityPolicy($capabilities);

        // Reads are user-scoped by policy; act as an administrator so all rows are
        // visible (the "still serving old generation" assertion is about generations,
        // not per-user filtering, which is covered by other tests).
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        return new \Dbvc\VisualEditor\MediaManager\MediaIndexReadModel($this->store, $policy);
    }

    /**
     * @return \Dbvc\VisualEditor\MediaManager\MediaIndexInvalidator
     */
    private function makeInvalidator()
    {
        $capabilities = new \Dbvc\VisualEditor\Permissions\CapabilityManager();
        $policy = new \Dbvc\VisualEditor\MediaManager\EligibilityPolicy($capabilities);
        $catalog = new \Dbvc\VisualEditor\MediaManager\AcfMediaFieldCatalog($policy);
        $reader = static function ($selector, $object_id) {
            return get_post_meta(absint($object_id), (string) $selector, true);
        };
        $scanner = new \Dbvc\VisualEditor\MediaManager\MediaScanService(
            $catalog,
            new \Dbvc\VisualEditor\MediaManager\MediaAssignmentValueClassifier(),
            $reader
        );
        $projector = new \Dbvc\VisualEditor\MediaManager\MediaIndexProjector($this->store);

        return new \Dbvc\VisualEditor\MediaManager\MediaIndexInvalidator(
            $this->store,
            $scanner,
            $policy,
            $projector
        );
    }

    /**
     * @return \Dbvc\VisualEditor\MediaManager\MediaIndexBuilder
     */
    private function makeBuilder()
    {
        $capabilities = new \Dbvc\VisualEditor\Permissions\CapabilityManager();
        $policy = new \Dbvc\VisualEditor\MediaManager\EligibilityPolicy($capabilities, true);
        $catalog = new \Dbvc\VisualEditor\MediaManager\AcfMediaFieldCatalog($policy);
        $reader = static function ($selector, $object_id) {
            return get_post_meta(absint($object_id), (string) $selector, true);
        };

        return new \Dbvc\VisualEditor\MediaManager\MediaIndexBuilder(
            $this->store,
            new \Dbvc\VisualEditor\MediaManager\ScanCandidateProvider($policy),
            new \Dbvc\VisualEditor\MediaManager\MediaScanService(
                $catalog,
                new \Dbvc\VisualEditor\MediaManager\MediaAssignmentValueClassifier(),
                $reader
            ),
            new \Dbvc\VisualEditor\MediaManager\MediaIndexProjector($this->store)
        );
    }

    /**
     * @param \Dbvc\VisualEditor\MediaManager\MediaIndexBuilder $builder
     * @return \Dbvc\VisualEditor\MediaManager\MediaIndexRebuildController
     */
    private function makeController($builder)
    {
        $invalidator = $this->makeInvalidator();
        $reconciler = new \Dbvc\VisualEditor\MediaManager\MediaIndexReconciler($this->store, $invalidator);
        $scheduler = new \Dbvc\VisualEditor\MediaManager\MediaIndexScheduler($reconciler, $builder);

        return new \Dbvc\VisualEditor\MediaManager\MediaIndexRebuildController(
            $this->store,
            $builder,
            $scheduler
        );
    }

    /**
     * @return void
     */
    private function seedAcfDefinitions()
    {
        $GLOBALS['dbvc_test_acf_groups'] = [
            [
                'key' => 'group_bld_media',
                'title' => 'Rebuild Media',
                'active' => true,
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'dbvc_rbld']]],
            ],
        ];
        $GLOBALS['dbvc_test_acf_fields'] = [
            'group_bld_media' => [
                ['key' => 'hero_image', 'name' => 'hero_image', 'label' => 'Hero image', 'type' => 'image'],
            ],
        ];
        $GLOBALS['dbvc_test_acf_group_visibility'] = static function ($group, $screen) {
            if (($group['key'] ?? '') !== 'group_bld_media') {
                return false;
            }

            return ($screen['post_type'] ?? '') === 'dbvc_rbld';
        };
    }
}
