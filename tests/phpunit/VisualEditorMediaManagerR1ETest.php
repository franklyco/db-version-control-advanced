<?php

// ACF is not installed in the PHPUnit environment. These guarded shims mirror the
// ones used by the R1-B scanner test so the catalog can evaluate applicability and
// read raw values from injected fixtures without depending on test-file load order.
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

final class VisualEditorMediaManagerR1ETest extends WP_UnitTestCase
{
    /**
     * @var array<string, mixed>
     */
    private $previous_options = [];

    /**
     * @var array<int, string>
     */
    private $scan_refs = [];

    /**
     * @var int
     */
    private $post_id = 0;

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
        update_option(DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_POST_TYPES, '');
        update_option(DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_TAXONOMIES, '');

        register_post_type('dbvc_mm_scale', [
            'label' => 'Media Manager Scale Content',
            'public' => true,
            'show_ui' => true,
            'supports' => ['title', 'thumbnail'],
        ]);

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->post_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Media Manager scale owner',
            'post_type' => 'dbvc_mm_scale',
        ]);
        $_COOKIE['dbvc_visual_editor_mode'] = '1';
        $this->go_to(get_permalink($this->post_id));
        $this->resetAssetHandles();
    }

    public function tear_down(): void
    {
        $store = new \Dbvc\VisualEditor\MediaManager\ScanSnapshotStore();
        foreach ($this->scan_refs as $scan_ref) {
            $store->delete($scan_ref);
        }

        unset(
            $GLOBALS['dbvc_test_acf_groups'],
            $GLOBALS['dbvc_test_acf_fields'],
            $GLOBALS['dbvc_test_acf_group_visibility'],
            $GLOBALS['dbvc_test_acf_values'],
            $GLOBALS['dbvc_test_mm_raw_reads'],
            $GLOBALS['dbvc_test_mm_visibility_calls']
        );

        unset($_COOKIE['dbvc_visual_editor_mode']);
        $this->resetAssetHandles();

        if (post_type_exists('dbvc_mm_scale')) {
            unregister_post_type('dbvc_mm_scale');
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

    public function test_ordinary_frontend_enqueue_does_not_create_or_advance_a_scan(): void
    {
        $store = new \Dbvc\VisualEditor\MediaManager\ScanSnapshotStore();
        $before = $store->loadLatest();
        $this->assertWPError($before);
        $this->assertSame('media_scan_expired_or_invalid', $before->get_error_code());

        $this->assetLoader()->enqueue();

        $after = $store->loadLatest();
        $this->assertWPError($after);
        $this->assertSame('media_scan_expired_or_invalid', $after->get_error_code());
        $this->assertTrue(wp_script_is('dbvc-visual-editor-media-manager', 'enqueued'));
    }

    public function test_bounded_read_model_and_snapshot_storage_at_100_500_and_2000_groups(): void
    {
        $services = $this->services();
        $evidence = [];

        foreach ([100, 500, 2000] as $group_count) {
            $snapshot = $services['store']->create($this->syntheticSnapshot($group_count));
            $this->assertNotWPError($snapshot);
            $this->scan_refs[] = (string) $snapshot['scan_ref'];

            $query_start = get_num_queries();
            $memory_start = memory_get_usage(true);
            $started_at = microtime(true);
            $result = $services['read_model']->getList($snapshot['scan_ref'], [
                'generation' => $snapshot['generation'],
                'expectedRevision' => $snapshot['revision'],
                'sort' => 'missing_desc',
                'limit' => 50,
            ]);
            $duration_ms = round((microtime(true) - $started_at) * 1000, 3);
            $query_count = get_num_queries() - $query_start;
            $memory_delta = max(0, memory_get_usage(true) - $memory_start);
            $payload = wp_json_encode($result);
            $payload_bytes = is_string($payload) ? strlen($payload) : 0;

            $this->assertNotWPError($result);
            $this->assertCount(50, $result['items']);
            $this->assertTrue($result['pagination']['hasMore']);
            $this->assertMatchesRegularExpression('/^vemg_[a-f0-9]{20}$/', $result['pagination']['nextCursor']);
            $this->assertSame(50, $result['query']['limit']);
            $this->assertSame($group_count, $result['scan']['summary']['entitiesWithFindings']);
            $this->assertLessThan(131072, $payload_bytes);
            $this->assertLessThan(2000, $duration_ms);
            $this->assertLessThanOrEqual(20, $query_count);
            $this->assertLessThanOrEqual(33554432, $memory_delta);
            $this->assertLessThan(5242880, $snapshot['storage']['snapshot_bytes']);
            $this->assertSame($group_count, $snapshot['storage']['group_count']);

            $evidence[(string) $group_count] = [
                'duration_ms' => $duration_ms,
                'query_count' => $query_count,
                'memory_delta_bytes' => $memory_delta,
                'snapshot_bytes' => $snapshot['storage']['snapshot_bytes'],
                'response_bytes' => $payload_bytes,
                'response_items' => count($result['items']),
            ];
        }

        fwrite(STDOUT, "\nDBVC Media Manager R1-E scale evidence: " . wp_json_encode($evidence) . "\n");
    }

    /**
     * Complete-traversal evidence: unlike the synthetic snapshot test above, this drives the
     * real ScanCandidateProvider -> MediaScanService -> ScanSnapshotStore pipeline across many
     * live owners so every candidate is actually enumerated and raw-read. It measures whether
     * DB queries, raw ACF reads, and applicability evaluations grow proportionally to owners
     * (i.e. no field-definition/capability/permalink N+1) rather than super-linearly.
     */
    public function test_complete_candidate_traversal_and_raw_reads_scale_without_field_definition_n_plus_one(): void
    {
        $this->seedTraversalAcfDefinitions();

        // Constrain the eligible candidate set toward the scale CPT so the traversal/finding
        // math stays deterministic. Correctness assertions below still filter to our owner subtype.
        update_option(DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_POST_TYPES, 'post,page,attachment');
        update_option(DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_TAXONOMIES, 'category,post_tag,post_format,nav_menu,link_category');

        $chunk_size = 50;
        $tiers = [100, 300];
        $created_total = 1; // set_up() already created one publishable dbvc_mm_scale owner.
        $this->seedTraversalOwnerValues([$this->post_id]);

        $evidence = [];
        $ratios = [];

        foreach ($tiers as $owner_count) {
            // Grow the live owner set cumulatively to the current tier size.
            $new_ids = [];
            for (; $created_total < $owner_count; $created_total++) {
                $new_ids[] = self::factory()->post->create([
                    'post_type' => 'dbvc_mm_scale',
                    'post_status' => 'publish',
                    'post_title' => sprintf('Scale traversal owner %04d', $created_total),
                ]);
            }
            $this->seedTraversalOwnerValues($new_ids);

            $GLOBALS['dbvc_test_mm_raw_reads'] = 0;
            $GLOBALS['dbvc_test_mm_visibility_calls'] = 0;

            $services = $this->traversalServices($chunk_size);

            $query_start = get_num_queries();
            $started_at = microtime(true);
            $snapshot = $services['coordinator']->start();
            $this->assertNotWPError($snapshot);
            $this->scan_refs[] = (string) $snapshot['scan_ref'];

            $iterations = 0;
            $max_chunk_candidates = 0;
            $max_chunk_source_queries = 0;
            while ($snapshot['state'] === 'scanning' && $iterations < 1000) {
                $snapshot = $services['coordinator']->runNextChunk(
                    $snapshot['scan_ref'],
                    $snapshot['generation'],
                    $snapshot['revision']
                );
                $this->assertNotWPError($snapshot);
                $max_chunk_candidates = max(
                    $max_chunk_candidates,
                    (int) ($snapshot['performance']['last_chunk']['candidate_count'] ?? 0)
                );
                $max_chunk_source_queries = max(
                    $max_chunk_source_queries,
                    (int) ($snapshot['performance']['last_chunk']['source_queries'] ?? 0)
                );
                $iterations++;
            }
            $wall_ms = round((microtime(true) - $started_at) * 1000, 3);
            $total_queries = get_num_queries() - $query_start;

            $this->assertSame('complete', $snapshot['state']);
            $this->assertLessThan(1000, $iterations);
            $this->assertLessThanOrEqual($chunk_size, $max_chunk_candidates);

            // Deterministic correctness: every one of our live owners is enumerated exactly once
            // and raw-read into its three expected empty findings (featured + ACF image + gallery).
            $owned = array_filter(
                $snapshot['groups'],
                static function ($group) {
                    return isset($group['owner']['subtype']) && $group['owner']['subtype'] === 'dbvc_mm_scale';
                }
            );
            $this->assertCount($owner_count, $owned);
            foreach ($owned as $group) {
                $families = array_map(
                    static function ($finding) {
                        return $finding['family'];
                    },
                    array_values($group['findings'])
                );
                sort($families);
                $this->assertSame(['acf_gallery', 'acf_image', 'featured_image'], $families);
            }

            $this->assertGreaterThanOrEqual($owner_count, (int) $snapshot['summary']['candidate_entities_processed']);
            $this->assertGreaterThanOrEqual($owner_count, (int) $snapshot['summary']['entities_with_findings']);
            $this->assertGreaterThanOrEqual($owner_count * 3, (int) $snapshot['summary']['total_findings']);

            // Raw ACF reads are exactly two per owner (image + group); the per-owner cost is a
            // constant that does not grow as the owner set grows.
            $raw_reads = (int) $GLOBALS['dbvc_test_mm_raw_reads'];
            $this->assertSame($owner_count * 2, $raw_reads);

            // Applicability is evaluated at most once per processed candidate: no per-owner
            // definition/visibility re-evaluation storm.
            $visibility_calls = (int) $GLOBALS['dbvc_test_mm_visibility_calls'];
            $this->assertLessThanOrEqual(
                (int) $snapshot['summary']['candidate_entities_processed'],
                $visibility_calls
            );

            $candidates = max(1, (int) $snapshot['summary']['candidate_entities_processed']);
            $ratios[$owner_count] = $total_queries / $candidates;

            $evidence[(string) $owner_count] = [
                'candidate_entities_processed' => (int) $snapshot['summary']['candidate_entities_processed'],
                'entities_with_findings' => (int) $snapshot['summary']['entities_with_findings'],
                'total_findings' => (int) $snapshot['summary']['total_findings'],
                'chunk_iterations' => $iterations,
                'max_chunk_candidates' => $max_chunk_candidates,
                'max_chunk_source_queries' => $max_chunk_source_queries,
                'total_db_queries' => $total_queries,
                'db_queries_per_candidate' => round($ratios[$owner_count], 3),
                'raw_acf_reads' => $raw_reads,
                'acf_applicability_evaluations' => $visibility_calls,
                'max_memory_delta_bytes' => (int) ($snapshot['performance']['max_memory_delta_bytes'] ?? 0),
                'snapshot_bytes' => (int) ($snapshot['storage']['snapshot_bytes'] ?? 0),
                'wall_ms' => $wall_ms,
            ];
        }

        // N+1 discriminator: tripling the owner set must not more than double the per-candidate DB
        // cost. A field-definition/capability/permalink N+1 nested in the traversal would drive
        // per-candidate cost up with scale; a quadratic pattern would roughly triple it.
        $this->assertLessThanOrEqual($ratios[100] * 2.0 + 0.001, $ratios[300]);

        fwrite(
            STDOUT,
            "\nDBVC Media Manager R1-E candidate-traversal/raw-read evidence: " . wp_json_encode($evidence) . "\n"
        );
    }

    /**
     * @param int $chunk_size
     * @return array<string, object>
     */
    private function traversalServices($chunk_size)
    {
        $capabilities = new \Dbvc\VisualEditor\Permissions\CapabilityManager();
        $policy = new \Dbvc\VisualEditor\MediaManager\EligibilityPolicy($capabilities);
        $catalog = new \Dbvc\VisualEditor\MediaManager\AcfMediaFieldCatalog($policy);
        $provider = new \Dbvc\VisualEditor\MediaManager\ScanCandidateProvider($policy, $chunk_size);
        $store = new \Dbvc\VisualEditor\MediaManager\ScanSnapshotStore();
        $scanner = new \Dbvc\VisualEditor\MediaManager\MediaScanService(
            $catalog,
            new \Dbvc\VisualEditor\MediaManager\MediaAssignmentValueClassifier(),
            static function ($selector, $object_id) {
                $GLOBALS['dbvc_test_mm_raw_reads'] = (int) ($GLOBALS['dbvc_test_mm_raw_reads'] ?? 0) + 1;
                $key = (string) $object_id . '|' . (string) $selector;

                return array_key_exists($key, $GLOBALS['dbvc_test_acf_values'] ?? [])
                    ? $GLOBALS['dbvc_test_acf_values'][$key]
                    : null;
            }
        );
        $coordinator = new \Dbvc\VisualEditor\MediaManager\MediaScanCoordinator(
            $provider,
            $scanner,
            $store,
            $catalog,
            $chunk_size
        );

        return compact('coordinator', 'store');
    }

    /**
     * @return void
     */
    private function seedTraversalAcfDefinitions()
    {
        $GLOBALS['dbvc_test_acf_groups'] = [
            [
                'key' => 'group_scan_media',
                'title' => 'Scan Media',
                'active' => true,
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'dbvc_mm_scale']]],
            ],
        ];
        $GLOBALS['dbvc_test_acf_fields'] = [
            'group_scan_media' => [
                ['key' => 'field_scan_image', 'name' => 'hero_image', 'label' => 'Hero image', 'type' => 'image'],
                [
                    'key' => 'field_scan_media_group',
                    'name' => 'media_group',
                    'label' => 'Media group',
                    'type' => 'group',
                    'sub_fields' => [
                        ['key' => 'field_scan_gallery', 'name' => 'gallery', 'label' => 'Gallery', 'type' => 'gallery'],
                    ],
                ],
            ],
        ];
        $GLOBALS['dbvc_test_mm_visibility_calls'] = 0;
        $GLOBALS['dbvc_test_acf_group_visibility'] = static function ($group, $screen) {
            $GLOBALS['dbvc_test_mm_visibility_calls'] = (int) ($GLOBALS['dbvc_test_mm_visibility_calls'] ?? 0) + 1;
            if (($group['key'] ?? '') !== 'group_scan_media') {
                return false;
            }

            return ($screen['post_type'] ?? '') === 'dbvc_mm_scale';
        };
        $GLOBALS['dbvc_test_acf_values'] = [];
    }

    /**
     * @param array<int, int> $owner_ids
     * @return void
     */
    private function seedTraversalOwnerValues(array $owner_ids)
    {
        if (! isset($GLOBALS['dbvc_test_acf_values']) || ! is_array($GLOBALS['dbvc_test_acf_values'])) {
            $GLOBALS['dbvc_test_acf_values'] = [];
        }

        foreach ($owner_ids as $owner_id) {
            $GLOBALS['dbvc_test_acf_values'][$owner_id . '|field_scan_image'] = null;
            $GLOBALS['dbvc_test_acf_values'][$owner_id . '|field_scan_media_group'] = ['gallery' => []];
        }
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
        $store = new \Dbvc\VisualEditor\MediaManager\ScanSnapshotStore();
        $scanner = new \Dbvc\VisualEditor\MediaManager\MediaScanService(
            $catalog,
            new \Dbvc\VisualEditor\MediaManager\MediaAssignmentValueClassifier()
        );
        $coordinator = new \Dbvc\VisualEditor\MediaManager\MediaScanCoordinator(
            $provider,
            $scanner,
            $store,
            $catalog,
            50
        );
        $read_model = new \Dbvc\VisualEditor\MediaManager\MediaScanReadModel(
            $coordinator,
            $scanner,
            $policy
        );

        return compact('store', 'read_model');
    }

    /**
     * @param int $group_count
     * @return array<string, mixed>
     */
    private function syntheticSnapshot($group_count)
    {
        $groups = [];
        $now = time();
        for ($index = 0; $index < $group_count; $index++) {
            $group_ref = 'vemg_' . substr(hash('sha256', 'group|' . $group_count . '|' . $index), 0, 20);
            $finding_ref = 'vemf_' . substr(hash('sha256', 'finding|' . $group_count . '|' . $index), 0, 20);
            $family = ['featured_image', 'acf_image', 'acf_gallery'][$index % 3];
            $groups[$group_ref] = [
                'group_ref' => $group_ref,
                'owner' => [
                    'family' => 'post',
                    'subtype' => 'dbvc_mm_scale',
                    'id' => $this->post_id,
                ],
                'entity_label' => sprintf('Synthetic media row %04d', $index + 1),
                'entity_type_label' => 'Media Manager Scale Content',
                'scanned_at' => $now - $index,
                'modified_gmt' => '2026-08-16 00:00:00',
                'findings' => [
                    $finding_ref => [
                        'finding_ref' => $finding_ref,
                        'family' => $family,
                        'context_label' => 'Synthetic media field',
                        'field' => [
                            'field_label' => 'Synthetic media field',
                        ],
                    ],
                ],
            ];
        }

        return [
            'schema_version' => 1,
            'scanner_version' => 1,
            'state' => 'complete',
            'progress' => [
                'processed' => $group_count,
                'total_estimate' => $group_count,
                'chunks' => (int) ceil($group_count / 50),
                'attempts' => (int) ceil($group_count / 50),
                'retry_count' => 0,
            ],
            'summary' => [
                'candidate_entities_processed' => $group_count,
                'entities_with_findings' => $group_count,
                'total_findings' => $group_count,
                'featured_image_findings' => (int) ceil($group_count / 3),
                'acf_image_findings' => (int) floor(($group_count + 1) / 3),
                'acf_gallery_findings' => (int) floor($group_count / 3),
                'unsupported_field_observations' => 0,
                'invalid_nonempty_values' => 0,
            ],
            'diagnostics' => [],
            'performance' => [],
            'groups' => $groups,
            'last_error' => [],
            'started_at' => $now - 60,
            'completed_at' => $now,
        ];
    }

    /**
     * @return \Dbvc\VisualEditor\Assets\AssetLoader
     */
    private function assetLoader()
    {
        $capabilities = new \Dbvc\VisualEditor\Permissions\CapabilityManager();
        $page_context = new \Dbvc\VisualEditor\Context\PageContextResolver();
        $edit_mode = new \Dbvc\VisualEditor\Context\EditModeState(
            $capabilities,
            $page_context,
            new \Dbvc\VisualEditor\Context\FrontendRuntimeGuard()
        );
        $registry = new \Dbvc\VisualEditor\Registry\EditableRegistry($page_context);

        return new \Dbvc\VisualEditor\Assets\AssetLoader(
            dirname(__DIR__, 2) . '/addons/visual-editor/bootstrap.php',
            $edit_mode,
            $registry,
            $page_context
        );
    }

    /**
     * @return void
     */
    private function resetAssetHandles()
    {
        foreach ([
            'dbvc-visual-editor-media-manager',
            'dbvc-visual-editor-overlay',
            'dbvc-visual-editor-api-client',
        ] as $handle) {
            wp_dequeue_script($handle);
            wp_deregister_script($handle);
            wp_dequeue_style($handle);
            wp_deregister_style($handle);
        }
    }
}
