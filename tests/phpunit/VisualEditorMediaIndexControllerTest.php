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
 * R2-H Phase 1 Slice 2b — index-backed REST plumbing.
 *
 * Verifies the stable opaque entity ref (resolvable only via the store), that the
 * index-expand route builds a detached per-entity snapshot WITHOUT clobbering the
 * user's latest full scan, returns the live detail with usable scan/group refs for
 * the existing mutation routes, and fails closed for a forged ref or an entity the
 * requesting user may no longer view.
 */
final class VisualEditorMediaIndexControllerTest extends WP_UnitTestCase
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

        register_post_type('dbvc_ixc', [
            'label' => 'Index Controller Content',
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
        if (post_type_exists('dbvc_ixc')) {
            unregister_post_type('dbvc_ixc');
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

    public function test_entity_ref_is_opaque_and_resolvable_only_via_the_store(): void
    {
        $context = $this->completedScanAndIndex('Ref owner');
        $ref = $context['entity_ref'];
        $this->assertMatchesRegularExpression('/^vemx_[a-f0-9]{24}$/', $ref);

        $resolved = $this->store->getByEntityRef($ref);
        $this->assertIsArray($resolved);
        $this->assertSame($context['post_id'], $resolved['entity_id']);

        // A forged ref matches no row.
        $this->assertNull($this->store->getByEntityRef('vemx_' . str_repeat('a', 24)));
        $this->assertNull($this->store->getByEntityRef('not-a-ref'));
    }

    public function test_index_expand_returns_live_detail_without_clobbering_latest(): void
    {
        $context = $this->completedScanAndIndex('Expand owner');
        $latest_before = $context['coordinator']->loadLatest();
        $this->assertNotWPError($latest_before);
        $latest_ref_before = (string) $latest_before['scan_ref'];

        $response = $context['controller']->handleIndexExpand($this->request(['entityRef' => $context['entity_ref']]));
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('index', $data['source']);
        $this->assertArrayHasKey('row', $data);
        $this->assertNotEmpty($data['row']['fields']);
        // The per-entity snapshot is usable by the existing mutation routes...
        $this->assertMatchesRegularExpression('/^vems_[a-z0-9_]+$/', (string) $data['scan']['scanRef']);
        // ...and it did NOT replace the user's latest full scan.
        $latest_after = $context['coordinator']->loadLatest();
        $this->assertSame($latest_ref_before, (string) $latest_after['scan_ref']);
        $this->assertNotSame($latest_ref_before, (string) $data['scan']['scanRef']);

        $this->scan_refs[] = (string) $data['scan']['scanRef'];
    }

    public function test_index_expand_rejects_a_forged_ref(): void
    {
        $context = $this->completedScanAndIndex('Forged owner');
        $response = $context['controller']->handleIndexExpand($this->request(['entityRef' => 'vemx_' . str_repeat('b', 24)]));
        $this->assertSame(404, $response->get_status());
        $this->assertSame('media_index_ref_unavailable', $response->get_data()['code']);
    }

    public function test_index_expand_fails_closed_when_the_user_cannot_view_the_entity(): void
    {
        $context = $this->completedScanAndIndex('Restricted owner');
        wp_update_post(['ID' => $context['post_id'], 'post_status' => 'draft']);

        $response = $context['controller']->handleIndexExpand($this->request(['entityRef' => $context['entity_ref']]));
        $this->assertSame(403, $response->get_status());
        $this->assertSame('media_index_forbidden', $response->get_data()['code']);
    }

    public function test_index_list_returns_the_filtered_index(): void
    {
        $context = $this->completedScanAndIndex('Listed owner');
        $response = $context['controller']->handleIndexList($this->request([]));
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('index', $data['source']);
        $labels = array_map(static function ($item) {
            return $item['entity']['label'];
        }, $data['items']);
        $this->assertContains('Listed owner', $labels);
    }

    /**
     * @param array<string, mixed> $params
     * @return WP_REST_Request
     */
    private function request(array $params)
    {
        $request = new WP_REST_Request('POST', '/dbvc/v1/visual-editor/media-manager/index/expand');
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return $request;
    }

    /**
     * @param string $title
     * @return array<string, mixed>
     */
    private function completedScanAndIndex($title)
    {
        $post_id = self::factory()->post->create([
            'post_type' => 'dbvc_ixc',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);

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
        $services['projector']->rebuildFromSnapshot($loaded);
        $row = $this->store->getEntity('post', $post_id, 'dbvc_ixc');
        $this->assertIsArray($row);

        return array_merge($services, [
            'post_id' => $post_id,
            'entity_ref' => (string) $row['entity_ref'],
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
        $read_model = new \Dbvc\VisualEditor\MediaManager\MediaScanReadModel($coordinator, $scanner, $policy);
        $projector = new \Dbvc\VisualEditor\MediaManager\MediaIndexProjector($this->store);
        $index_read_model = new \Dbvc\VisualEditor\MediaManager\MediaIndexReadModel($this->store, $policy);
        $controller = new \Dbvc\VisualEditor\Rest\Controllers\MediaIndexController(
            $capabilities,
            $index_read_model,
            $this->store,
            $coordinator,
            $read_model,
            $policy
        );

        return compact('coordinator', 'projector', 'controller');
    }

    /**
     * @return void
     */
    private function seedAcfDefinitions()
    {
        $GLOBALS['dbvc_test_acf_groups'] = [
            [
                'key' => 'group_ixc_media',
                'title' => 'Index Controller Media',
                'active' => true,
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'dbvc_ixc']]],
            ],
        ];
        $GLOBALS['dbvc_test_acf_fields'] = [
            'group_ixc_media' => [
                ['key' => 'hero_image', 'name' => 'hero_image', 'label' => 'Hero image', 'type' => 'image'],
            ],
        ];
        $GLOBALS['dbvc_test_acf_group_visibility'] = static function ($group, $screen) {
            if (($group['key'] ?? '') !== 'group_ixc_media') {
                return false;
            }

            return ($screen['post_type'] ?? '') === 'dbvc_ixc';
        };
    }
}
