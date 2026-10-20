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
 * R2-H Phase 1 Slice 4b — the automatic first-run structural build.
 *
 * Proves the cross-user builder indexes the STRUCTURAL eligible set (no per-object
 * capability — so it indexes entities the acting user could never edit), advances in
 * bounded chunks with a cursor persisted across runs until complete, and that a manual
 * scan refresh never clobbers the built index or its generation.
 */
final class VisualEditorMediaIndexBuilderTest extends WP_UnitTestCase
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
        ] as $option_name) {
            $this->previous_options[$option_name] = get_option($option_name, '__dbvc_missing_option__');
        }

        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_POST_TYPES, 'post,page,attachment');
        update_option(DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_TAXONOMIES, 'category,post_tag,post_format,nav_menu,link_category');
        delete_option('dbvc_visual_editor_media_index_build');

        register_post_type('dbvc_bld', [
            'label' => 'Builder Content',
            'public' => true,
            'show_ui' => true,
            'supports' => ['title'],
        ]);

        $this->seedAcfDefinitions();

        $this->store = new \Dbvc\VisualEditor\MediaManager\MediaIndexStore();
        $this->store->maybeUpgrade();
        $this->store->deleteAll();
    }

    public function tear_down(): void
    {
        $this->store->deleteAll();
        unset(
            $GLOBALS['dbvc_test_acf_groups'],
            $GLOBALS['dbvc_test_acf_fields'],
            $GLOBALS['dbvc_test_acf_group_visibility']
        );
        if (post_type_exists('dbvc_bld')) {
            unregister_post_type('dbvc_bld');
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

    public function test_structural_build_indexes_entities_the_current_user_cannot_edit(): void
    {
        $ids = $this->createPosts(3);

        // Act as a subscriber who cannot edit any of these posts.
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);

        // A capability-scoped build finds nothing for a subscriber…
        $this->makeBuilder(false)->runChunk();
        $this->assertSame(0, $this->store->countEntities([]));

        // …while the structural build indexes every published post with missing media.
        // (Production wires only the structural builder; reset the shared build state
        // so this second builder does not see the first's "complete" marker.)
        $structural = $this->makeBuilder(true);
        $structural->reset();
        $result = $structural->runChunk();
        $this->assertTrue($result['complete']);
        foreach ($ids as $id) {
            $row = $this->store->getEntity('post', $id, 'dbvc_bld');
            $this->assertIsArray($row, 'Structural build should index a post the user cannot edit.');
            $this->assertGreaterThan(0, absint($row['missing_count']));
        }
    }

    public function test_build_advances_in_chunks_and_completes(): void
    {
        $ids = $this->createPosts(5);
        $builder = $this->makeBuilder(true);
        $this->assertTrue($builder->needsBuild());

        // Drive one small chunk at a time; the cursor must persist across calls.
        $runs = 0;
        do {
            $result = $builder->runChunk(2);
            $runs++;
        } while (! $result['complete'] && $runs < 20);

        $this->assertTrue($result['complete']);
        $this->assertGreaterThan(1, $runs, 'A 5-entity build with a 2-chunk limit should take multiple runs.');
        $this->assertFalse($builder->needsBuild());
        $this->assertSame(count($ids), $this->store->countEntities([]));
    }

    public function test_needs_build_lifecycle_and_reset(): void
    {
        $this->createPosts(2);
        $builder = $this->makeBuilder(true);

        $this->assertTrue($builder->needsBuild());
        do {
            $result = $builder->runChunk(50);
        } while (! $result['complete']);
        $this->assertFalse($builder->needsBuild());

        $builder->reset();
        $this->assertTrue($builder->needsBuild());
    }

    public function test_manual_scan_refresh_does_not_clobber_the_build(): void
    {
        $ids = $this->createPosts(2);
        $builder = $this->makeBuilder(true);
        do {
            $result = $builder->runChunk(50);
        } while (! $result['complete']);
        $this->assertSame(2, $this->store->countEntities([]));

        $generation_before = $this->store->currentGeneration();

        // A manual scan of only the first post refreshes it in place (the scanner uses
        // its own vmsg_ scan generation; the projector re-stores under the index one).
        $scanned = $this->makeScanner(true)->scan([
            ['family' => 'post', 'subtype' => 'dbvc_bld', 'id' => $ids[0]],
        ], 'vmsg_' . str_repeat('a', 20));
        $this->assertNotWPError($scanned);

        $projector = new \Dbvc\VisualEditor\MediaManager\MediaIndexProjector($this->store);
        $projector->onScanRefreshed(['groups' => $scanned['groups']]);

        // The generation did not rotate and the untouched entity's row survived (no prune).
        $this->assertSame($generation_before, $this->store->currentGeneration());
        $this->assertSame(2, $this->store->countEntities([]));
        $this->assertIsArray($this->store->getEntity('post', $ids[1], 'dbvc_bld'));
    }

    public function test_scheduler_drives_and_stops_the_build(): void
    {
        $this->createPosts(3);
        $builder = $this->makeBuilder(true);
        $scheduler = $this->makeScheduler($builder);

        try {
            // Action Scheduler is absent, so registering with a pending build arms the
            // WP-Cron single-event drain.
            $this->assertFalse(wp_next_scheduled(\Dbvc\VisualEditor\MediaManager\MediaIndexScheduler::BUILD_HOOK));
            $scheduler->register();
            $this->assertNotFalse(wp_next_scheduled(\Dbvc\VisualEditor\MediaManager\MediaIndexScheduler::BUILD_HOOK));

            // Draining the build populates the index and completes it.
            $guard = 0;
            while ($builder->needsBuild() && $guard < 50) {
                $scheduler->runBuild();
                $guard++;
            }
            $this->assertFalse($builder->needsBuild());
            $this->assertSame(3, $this->store->countEntities([]));

            // Once complete, no further drain is armed.
            $timestamp = wp_next_scheduled(\Dbvc\VisualEditor\MediaManager\MediaIndexScheduler::BUILD_HOOK);
            if ($timestamp) {
                wp_unschedule_event($timestamp, \Dbvc\VisualEditor\MediaManager\MediaIndexScheduler::BUILD_HOOK);
            }
            $scheduler->ensureBuildScheduled();
            $this->assertFalse(wp_next_scheduled(\Dbvc\VisualEditor\MediaManager\MediaIndexScheduler::BUILD_HOOK));
        } finally {
            $scheduler->unregister();
        }
    }

    /**
     * @param \Dbvc\VisualEditor\MediaManager\MediaIndexBuilder $builder
     * @return \Dbvc\VisualEditor\MediaManager\MediaIndexScheduler
     */
    private function makeScheduler($builder)
    {
        $capabilities = new \Dbvc\VisualEditor\Permissions\CapabilityManager();
        $policy = new \Dbvc\VisualEditor\MediaManager\EligibilityPolicy($capabilities);
        $projector = new \Dbvc\VisualEditor\MediaManager\MediaIndexProjector($this->store);
        $invalidator = new \Dbvc\VisualEditor\MediaManager\MediaIndexInvalidator(
            $this->store,
            $this->makeScanner(false),
            $policy,
            $projector
        );
        $reconciler = new \Dbvc\VisualEditor\MediaManager\MediaIndexReconciler($this->store, $invalidator);

        return new \Dbvc\VisualEditor\MediaManager\MediaIndexScheduler($reconciler, $builder);
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
                'post_type' => 'dbvc_bld',
                'post_status' => 'publish',
                'post_title' => 'Builder ' . $i,
            ]);
        }

        return $ids;
    }

    /**
     * @param bool $structural
     * @return \Dbvc\VisualEditor\MediaManager\MediaScanService
     */
    private function makeScanner($structural)
    {
        $capabilities = new \Dbvc\VisualEditor\Permissions\CapabilityManager();
        $policy = new \Dbvc\VisualEditor\MediaManager\EligibilityPolicy($capabilities, $structural);
        $catalog = new \Dbvc\VisualEditor\MediaManager\AcfMediaFieldCatalog($policy);
        $reader = static function ($selector, $object_id) {
            return get_post_meta(absint($object_id), (string) $selector, true);
        };

        return new \Dbvc\VisualEditor\MediaManager\MediaScanService(
            $catalog,
            new \Dbvc\VisualEditor\MediaManager\MediaAssignmentValueClassifier(),
            $reader
        );
    }

    /**
     * @param bool $structural
     * @return \Dbvc\VisualEditor\MediaManager\MediaIndexBuilder
     */
    private function makeBuilder($structural)
    {
        $capabilities = new \Dbvc\VisualEditor\Permissions\CapabilityManager();
        $policy = new \Dbvc\VisualEditor\MediaManager\EligibilityPolicy($capabilities, $structural);

        return new \Dbvc\VisualEditor\MediaManager\MediaIndexBuilder(
            $this->store,
            new \Dbvc\VisualEditor\MediaManager\ScanCandidateProvider($policy),
            $this->makeScanner($structural),
            new \Dbvc\VisualEditor\MediaManager\MediaIndexProjector($this->store)
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
                'title' => 'Builder Media',
                'active' => true,
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'dbvc_bld']]],
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

            return ($screen['post_type'] ?? '') === 'dbvc_bld';
        };
    }
}
