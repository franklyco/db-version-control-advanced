<?php

// Guarded ACF shims (shared with the R2-A/R2-C/R2-F scanner tests).
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
 * R2-H Phase 1 Slice 2 — index population + read-time filtered listing.
 *
 * Verifies the projector writes a completed scan's per-entity summary into the
 * index, and that the index read model re-checks eligibility at read time for the
 * requesting user (the load-bearing security property of a shared, cross-user
 * index): an entity that lost eligibility after indexing, or that the current user
 * cannot edit, is filtered out even though its row remains in the shared table.
 */
final class VisualEditorMediaIndexPhase2Test extends WP_UnitTestCase
{
    /**
     * @var array<string, mixed>
     */
    private $previous_options = [];

    /**
     * @var int
     */
    private $administrator_id = 0;

    /**
     * @var array<int, string>
     */
    private $scan_refs = [];

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
        ] as $option_name) {
            $this->previous_options[$option_name] = get_option($option_name, '__dbvc_missing_option__');
        }

        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_POST_TYPES, 'post,page,attachment');
        update_option(DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_TAXONOMIES, 'category,post_tag,post_format,nav_menu,link_category');

        register_post_type('dbvc_idx', [
            'label' => 'Index Content',
            'public' => true,
            'show_ui' => true,
            'supports' => ['title', 'thumbnail'],
        ]);

        $this->administrator_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->administrator_id);
        $this->seedAcfDefinitions();

        $this->store = new \Dbvc\VisualEditor\MediaManager\MediaIndexStore();
        $this->store->maybeUpgrade();
        $this->store->deleteAll();
    }

    public function tear_down(): void
    {
        wp_set_current_user($this->administrator_id);
        $this->store->deleteAll();
        $snapshot_store = new \Dbvc\VisualEditor\MediaManager\ScanSnapshotStore();
        foreach ($this->scan_refs as $scan_ref) {
            $snapshot_store->delete($scan_ref);
        }

        unset(
            $GLOBALS['dbvc_test_acf_groups'],
            $GLOBALS['dbvc_test_acf_fields'],
            $GLOBALS['dbvc_test_acf_group_visibility']
        );

        if (post_type_exists('dbvc_idx')) {
            unregister_post_type('dbvc_idx');
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

    public function test_projector_writes_a_per_entity_summary_from_a_completed_scan(): void
    {
        $context = $this->completedScan(['Alpha index owner']);
        $result = $context['projector']->rebuildFromSnapshot($context['snapshot']);

        $this->assertGreaterThanOrEqual(1, $result['written']);
        $this->assertMatchesRegularExpression('/^vmig_[a-f0-9]{20}$/', $result['generation']);

        $row = $this->store->getEntity('post', $context['post_ids'][0], 'dbvc_idx');
        $this->assertIsArray($row);
        $this->assertGreaterThan(0, $row['missing_count']);
        $this->assertSame('Alpha index owner', $row['label']);
        $this->assertArrayHasKey('featured_image', $row['family_counts']);
        $this->assertSame($result['generation'], $row['index_generation']);
        $this->assertNotSame('', $row['content_hash']);
    }

    public function test_scan_completed_hook_populates_the_index(): void
    {
        $projector = new \Dbvc\VisualEditor\MediaManager\MediaIndexProjector($this->store);
        add_action('dbvc_visual_editor_media_scan_completed', [$projector, 'onScanCompleted']);

        $context = $this->completedScan(['Hooked owner']);

        remove_action('dbvc_visual_editor_media_scan_completed', [$projector, 'onScanCompleted']);

        // The coordinator fired the completion action, so the index is already built.
        $row = $this->store->getEntity('post', $context['post_ids'][0], 'dbvc_idx');
        $this->assertIsArray($row);
        $this->assertGreaterThan(0, $row['missing_count']);
    }

    public function test_read_model_lists_eligible_entities_for_the_current_user(): void
    {
        $context = $this->completedScan(['Listed owner']);
        $context['projector']->rebuildFromSnapshot($context['snapshot']);

        $list = $context['read_model']->getList();
        $this->assertSame('index', $list['source']);
        $refs = array_column($list['items'], 'groupRef');
        $this->assertNotEmpty($list['items']);
        $labels = array_map(static function ($item) {
            return $item['entity']['label'];
        }, $list['items']);
        $this->assertContains('Listed owner', $labels);
        // The projection never carries a field key/selector/path/raw target.
        $serialized = wp_json_encode($list['items']);
        foreach (['field_key', 'field_selector', 'acf_object_id', 'empty_fingerprint', 'group_path'] as $needle) {
            $this->assertStringNotContainsString($needle, (string) $serialized, $needle);
        }
        unset($refs);
    }

    public function test_entity_unpublished_after_indexing_is_filtered_out_at_read_time(): void
    {
        $context = $this->completedScan(['Vanishing owner']);
        $context['projector']->rebuildFromSnapshot($context['snapshot']);
        $post_id = $context['post_ids'][0];

        // Present before the status change.
        $before = array_column($context['read_model']->getList()['items'], 'entity');
        $this->assertContains('Vanishing owner', array_column($before, 'label'));

        // The row stays in the shared table, but read-time eligibility drops it.
        wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
        $after = $context['read_model']->getList();
        $labels = array_column(array_column($after['items'], 'entity'), 'label');
        $this->assertNotContains('Vanishing owner', $labels);
        // The stored row is untouched (still present for a capable user / re-publish).
        $this->assertIsArray($this->store->getEntity('post', $post_id, 'dbvc_idx'));
    }

    public function test_user_without_capability_sees_no_index_rows(): void
    {
        $context = $this->completedScan(['Private owner']);
        $context['projector']->rebuildFromSnapshot($context['snapshot']);

        // A subscriber lacks the per-object edit capability, so read-time filtering
        // removes every row even though the shared index is populated.
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $list = $context['read_model']->getList();
        $this->assertSame([], $list['items']);

        wp_set_current_user($this->administrator_id);
        $this->assertNotEmpty($context['read_model']->getList()['items']);
    }

    /**
     * @param array<int, string> $titles
     * @return array<string, mixed>
     */
    private function completedScan(array $titles)
    {
        $post_ids = [];
        foreach ($titles as $title) {
            $post_ids[] = self::factory()->post->create([
                'post_type' => 'dbvc_idx',
                'post_status' => 'publish',
                'post_title' => $title,
            ]);
        }

        $services = $this->services();
        $snapshot = $services['coordinator']->start();
        $this->assertNotWPError($snapshot);
        $this->scan_refs[] = (string) $snapshot['scan_ref'];

        $iterations = 0;
        while ($snapshot['state'] === 'scanning' && $iterations < 100) {
            $snapshot = $services['coordinator']->runNextChunk(
                $snapshot['scan_ref'],
                $snapshot['generation'],
                $snapshot['revision']
            );
            $this->assertNotWPError($snapshot);
            $iterations++;
        }
        $this->assertSame('complete', $snapshot['state']);

        $loaded = $services['coordinator']->load($snapshot['scan_ref']);
        $this->assertNotWPError($loaded);

        return array_merge($services, [
            'post_ids' => $post_ids,
            'snapshot' => $loaded,
        ]);
    }

    /**
     * @return array<string, object>
     */
    private function services()
    {
        $capabilities = new \Dbvc\VisualEditor\Permissions\CapabilityManager();
        $policy = new \Dbvc\VisualEditor\MediaManager\EligibilityPolicy($capabilities);
        $catalog = new \Dbvc\VisualEditor\MediaManager\AcfMediaFieldCatalog($policy);
        $provider = new \Dbvc\VisualEditor\MediaManager\ScanCandidateProvider($policy, 50);
        $snapshot_store = new \Dbvc\VisualEditor\MediaManager\ScanSnapshotStore();
        $reader = static function ($selector, $object_id) {
            return get_post_meta(absint($object_id), (string) $selector, true);
        };
        $scanner = new \Dbvc\VisualEditor\MediaManager\MediaScanService(
            $catalog,
            new \Dbvc\VisualEditor\MediaManager\MediaAssignmentValueClassifier(),
            $reader
        );
        $coordinator = new \Dbvc\VisualEditor\MediaManager\MediaScanCoordinator($provider, $scanner, $snapshot_store, $catalog, 50);
        $projector = new \Dbvc\VisualEditor\MediaManager\MediaIndexProjector($this->store);
        $read_model = new \Dbvc\VisualEditor\MediaManager\MediaIndexReadModel($this->store, $policy);

        return compact('coordinator', 'projector', 'read_model');
    }

    /**
     * @return void
     */
    private function seedAcfDefinitions()
    {
        $GLOBALS['dbvc_test_acf_groups'] = [
            [
                'key' => 'group_idx_media',
                'title' => 'Index Media',
                'active' => true,
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'dbvc_idx']]],
            ],
        ];
        $GLOBALS['dbvc_test_acf_fields'] = [
            'group_idx_media' => [
                ['key' => 'hero_image', 'name' => 'hero_image', 'label' => 'Hero image', 'type' => 'image'],
            ],
        ];
        $GLOBALS['dbvc_test_acf_group_visibility'] = static function ($group, $screen) {
            if (($group['key'] ?? '') !== 'group_idx_media') {
                return false;
            }

            return ($screen['post_type'] ?? '') === 'dbvc_idx';
        };
    }
}
