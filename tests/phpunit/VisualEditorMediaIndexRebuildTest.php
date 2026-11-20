<?php

require_once __DIR__ . '/support/MediaManagerEarlyBootShims.php';

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
     * @var bool
     */
    private $implicitly_committed = false;

    /**
     * @var array<int, int>
     */
    private $committed_post_ids = [];

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
            'dbvc_visual_editor_media_index_schema_version',
            'dbvc_visual_editor_media_index_generation',
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
        $this->restoreIdentityKey();
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
        $this->undoImplicitCommits();
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
     * Sites built on the pre-generation key kept it after the v2 stamp: dbDelta cannot
     * alter an existing index, so every building-gen insert for an already-indexed
     * entity was rejected. The upgrade must replace the key, and rebuild the index
     * because the last swap on that key pruned the rows it had rejected.
     */
    public function test_legacy_identity_key_is_repaired_and_index_rebuilt(): void
    {
        $this->committed_post_ids = $this->createPosts(4);
        $builder = $this->makeBuilder();
        $this->drainBuilder($builder);
        $serving_before = $this->store->currentGeneration();
        $this->assertSame(4, $this->store->countEntities(['generation' => $serving_before]));

        $this->installLegacyIdentityKey();
        $this->resetDatabaseErrors();

        $this->store->maybeUpgrade();

        $this->assertSame(
            ['entity_type', 'entity_id', 'entity_subtype', 'index_generation'],
            $this->identityKeyColumns()
        );
        $this->assertTrue($this->store->hasActiveRebuild(), 'A repaired key must rebuild the index.');
        $this->assertTrue($builder->needsBuild());
        $this->assertSame(4, $this->store->countEntities(['generation' => $serving_before]));

        $this->drainBuilder($builder);

        $this->assertFalse($this->store->hasActiveRebuild());
        $this->assertNotSame($serving_before, $this->store->currentGeneration());
        $this->assertSame(4, $this->store->countEntities(['generation' => $this->store->currentGeneration()]));
        $this->assertSame(4, $this->store->countEntities());
        $this->assertSame([], $this->duplicateDatabaseErrors());
    }

    /**
     * The live failure: a rebuild already part-way through on the legacy key has
     * dropped the rows in its first chunks, and its cursor is past them. Finishing it
     * would swap in a partial generation and prune the serving rows, so the repair
     * has to restart it from the top.
     */
    public function test_in_flight_rebuild_on_legacy_key_restarts_instead_of_losing_rows(): void
    {
        $this->committed_post_ids = $this->createPosts(4);
        $builder = $this->makeBuilder();
        $this->drainBuilder($builder);
        $serving_before = $this->store->currentGeneration();

        // Run a chunk the way the old release did: upgrade skipped, legacy key live.
        $this->installLegacyIdentityKey();
        update_option('dbvc_visual_editor_media_index_schema_version', '99');
        $abandoned = $this->store->beginRebuild();
        $builder->reset();
        $this->resetDatabaseErrors();
        $partial = $builder->runChunk(2);
        $this->assertSame(0, $partial['indexed'], 'The legacy key must reject the building-gen rows.');
        $this->assertCount(2, $this->duplicateDatabaseErrors());
        $this->assertSame(0, $this->store->countEntities(['generation' => $abandoned]));

        update_option('dbvc_visual_editor_media_index_schema_version', '2');
        $this->resetDatabaseErrors();
        $this->drainBuilder($builder);

        $serving_after = $this->store->currentGeneration();
        $this->assertNotSame($serving_before, $serving_after);
        $this->assertNotSame($abandoned, $serving_after);
        $this->assertSame(4, $this->store->countEntities(['generation' => $serving_after]));
        $this->assertSame(4, $this->store->countEntities());
        $this->assertSame([], $this->duplicateDatabaseErrors());
    }

    /**
     * The add-on reaches maybeUpgrade() while its plugin file is still loading, so
     * the repair, including the rebuild it starts, must not need pluggable functions.
     */
    public function test_legacy_key_repair_runs_before_pluggable_functions_load(): void
    {
        $this->committed_post_ids = $this->createPosts(2);
        $builder = $this->makeBuilder();
        $this->drainBuilder($builder);
        $serving_before = $this->store->currentGeneration();

        $this->installLegacyIdentityKey();
        $GLOBALS['dbvc_test_before_pluggables'] = true;
        try {
            $this->store->maybeUpgrade();
        } finally {
            unset($GLOBALS['dbvc_test_before_pluggables']);
        }

        $this->assertContains('index_generation', $this->identityKeyColumns());
        $this->assertTrue($this->store->hasActiveRebuild(), 'The repair must still start the rebuild.');

        $this->drainBuilder($builder);

        $this->assertNotSame($serving_before, $this->store->currentGeneration());
        $this->assertSame(2, $this->store->countEntities(['generation' => $this->store->currentGeneration()]));
    }

    public function test_upgrade_leaves_a_current_identity_key_and_index_alone(): void
    {
        $this->createPosts(2);
        $builder = $this->makeBuilder();
        $this->drainBuilder($builder);
        update_option('dbvc_visual_editor_media_index_schema_version', '2');

        $this->store->maybeUpgrade();

        $this->assertFalse($this->store->hasActiveRebuild());
        $this->assertFalse($builder->needsBuild());
        $this->assertGreaterThan(2, (int) get_option('dbvc_visual_editor_media_index_schema_version'));
    }

    /**
     * Put the table back in the shape a pre-generation install still has, stamped v2.
     *
     * @return void
     */
    private function installLegacyIdentityKey()
    {
        global $wpdb;

        $this->implicitly_committed = true;
        $table = $wpdb->prefix . 'dbvc_ve_media_field_index';
        $wpdb->query("ALTER TABLE {$table} DROP INDEX entity_identity, ADD UNIQUE KEY entity_identity (entity_type, entity_id, entity_subtype)");
        update_option('dbvc_visual_editor_media_index_schema_version', '2');
    }

    /**
     * ALTER TABLE commits implicitly, even on the suite's temporary tables, so what a
     * legacy-key test wrote before it survives the rollback and must be removed here.
     *
     * @return void
     */
    private function undoImplicitCommits()
    {
        global $wpdb;

        if (! $this->implicitly_committed) {
            return;
        }

        // The cache still holds the values tear_down set before the rollback, and
        // update_option() would skip a write that appears to change nothing.
        wp_cache_flush();
        foreach ($this->committed_post_ids as $post_id) {
            wp_delete_post($post_id, true);
        }
        $this->store->deleteAll();
        foreach ($this->previous_options as $option_name => $value) {
            if ($value === '__dbvc_missing_option__') {
                delete_option($option_name);
            } else {
                update_option($option_name, $value);
            }
        }
        $wpdb->query('COMMIT');
    }

    /**
     * @return array<int, string>
     */
    private function identityKeyColumns()
    {
        global $wpdb;

        $table = $wpdb->prefix . 'dbvc_ve_media_field_index';
        $rows = $wpdb->get_results("SHOW INDEX FROM {$table} WHERE Key_name = 'entity_identity'", ARRAY_A);

        return array_column(is_array($rows) ? $rows : [], 'Column_name');
    }

    /**
     * The table outlives a test, so a failed assertion must not leave the legacy key
     * behind for the rest of the suite.
     *
     * @return void
     */
    private function restoreIdentityKey()
    {
        global $wpdb;

        if (in_array('index_generation', $this->identityKeyColumns(), true)) {
            return;
        }

        $table = $wpdb->prefix . 'dbvc_ve_media_field_index';
        $wpdb->query("ALTER TABLE {$table} DROP INDEX entity_identity, ADD UNIQUE KEY entity_identity (entity_type, entity_id, entity_subtype, index_generation)");
    }

    /**
     * @return void
     */
    private function resetDatabaseErrors()
    {
        $GLOBALS['EZSQL_ERROR'] = [];
    }

    /**
     * Every "Duplicate entry" or "Duplicate key name" wpdb reported since the reset,
     * read from the list wpdb keeps even while errors are suppressed.
     *
     * @return array<int, string>
     */
    private function duplicateDatabaseErrors()
    {
        $messages = array_column((array) ($GLOBALS['EZSQL_ERROR'] ?? []), 'error_str');

        return array_values(array_filter($messages, static function ($message) {
            return stripos((string) $message, 'duplicate') !== false;
        }));
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
