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

final class VisualEditorMediaManagerR1BTest extends WP_UnitTestCase
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

        register_post_type('dbvc_mm_scan', [
            'label' => 'Media Scan Content',
            'public' => true,
            'show_ui' => true,
            'supports' => ['title', 'thumbnail'],
        ]);
        register_taxonomy('dbvc_mm_scan_tax', ['dbvc_mm_scan'], [
            'label' => 'Media Scan Terms',
            'public' => true,
            'show_ui' => true,
        ]);

        $this->administrator_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->administrator_id);
        $this->seedAcfDefinitions();
    }

    public function tear_down(): void
    {
        wp_set_current_user($this->administrator_id);
        $store = new \Dbvc\VisualEditor\MediaManager\ScanSnapshotStore();
        foreach ($this->scan_refs as $scan_ref) {
            $store->delete($scan_ref);
        }

        unset(
            $GLOBALS['dbvc_test_acf_groups'],
            $GLOBALS['dbvc_test_acf_fields'],
            $GLOBALS['dbvc_test_acf_group_visibility'],
            $GLOBALS['dbvc_test_acf_values'],
            $GLOBALS['dbvc_test_acf_value_reader']
        );

        if (taxonomy_exists('dbvc_mm_scan_tax')) {
            unregister_taxonomy('dbvc_mm_scan_tax');
        }
        if (post_type_exists('dbvc_mm_scan')) {
            unregister_post_type('dbvc_mm_scan');
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

    public function test_bounded_scan_completes_with_deterministic_compact_findings_and_metrics(): void
    {
        $fixture = $this->seedMediaEntities();
        $services = $this->services(2, 2);
        $snapshot = $services['coordinator']->start();

        $this->assertNotWPError($snapshot);
        $this->rememberScan($snapshot);
        $this->assertSame('scanning', $snapshot['state']);
        $this->assertSame(1, $snapshot['schema_version']);
        $this->assertSame(1, $snapshot['scanner_version']);
        $this->assertGreaterThanOrEqual(4, $snapshot['progress']['total_estimate']);
        $this->assertSame([], $snapshot['groups']);

        $iterations = 0;
        while ($snapshot['state'] === 'scanning' && $iterations < 100) {
            $snapshot = $services['coordinator']->runNextChunk(
                $snapshot['scan_ref'],
                $snapshot['generation'],
                $snapshot['revision']
            );
            $this->assertNotWPError($snapshot);
            $this->assertLessThanOrEqual(2, $snapshot['performance']['last_chunk']['candidate_count']);
            $this->assertLessThanOrEqual(2, $snapshot['performance']['last_chunk']['source_queries']);
            $iterations++;
        }

        $this->assertLessThan(100, $iterations);
        $this->assertSame('complete', $snapshot['state']);
        $this->assertSame(4, $snapshot['summary']['entities_with_findings']);
        $this->assertSame(7, $snapshot['summary']['total_findings']);
        $this->assertSame(2, $snapshot['summary']['featured_image_findings']);
        $this->assertSame(2, $snapshot['summary']['acf_image_findings']);
        $this->assertSame(3, $snapshot['summary']['acf_gallery_findings']);
        $this->assertSame(1, $snapshot['summary']['invalid_nonempty_values']);
        $this->assertGreaterThanOrEqual(4, $snapshot['summary']['unsupported_field_observations']);
        $this->assertGreaterThanOrEqual(4, $snapshot['summary']['candidate_entities_processed']);
        $this->assertGreaterThan(0, $snapshot['performance']['total_query_count']);
        $this->assertGreaterThanOrEqual(0, $snapshot['performance']['max_memory_delta_bytes']);
        $this->assertGreaterThan(0, $snapshot['storage']['snapshot_bytes']);

        $group_refs = array_keys($snapshot['groups']);
        $this->assertCount(4, $group_refs);
        foreach ($group_refs as $group_ref) {
            $this->assertMatchesRegularExpression('/^vemg_[a-f0-9]{20}$/', $group_ref);
            foreach (array_keys($snapshot['groups'][$group_ref]['findings']) as $finding_ref) {
                $this->assertMatchesRegularExpression('/^vemf_[a-f0-9]{20}$/', $finding_ref);
            }
        }

        $service_result_a = $services['scanner']->scan([
            ['family' => 'post', 'subtype' => 'dbvc_mm_scan', 'id' => $fixture['empty_post']],
        ], $snapshot['generation']);
        $service_result_b = $services['scanner']->scan([
            ['family' => 'post', 'subtype' => 'dbvc_mm_scan', 'id' => $fixture['empty_post']],
        ], $snapshot['generation']);
        $this->assertNotWPError($service_result_a);
        $this->assertSame(array_keys($service_result_a['groups']), array_keys($service_result_b['groups']));
        $first_group_a = reset($service_result_a['groups']);
        $first_group_b = reset($service_result_b['groups']);
        $this->assertSame(array_keys($first_group_a['findings']), array_keys($first_group_b['findings']));

        $serialized = wp_json_encode($snapshot);
        $this->assertIsString($serialized);
        $this->assertStringNotContainsString('legacy-image-url.jpg', $serialized);
    }

    public function test_duplicate_revision_and_active_lock_do_not_advance_or_corrupt_snapshot(): void
    {
        $this->seedMediaEntities();
        $services = $this->services(1, 1);
        $started = $services['coordinator']->start();
        $this->assertNotWPError($started);
        $this->rememberScan($started);

        $advanced = $services['coordinator']->runNextChunk(
            $started['scan_ref'],
            $started['generation'],
            $started['revision']
        );
        $this->assertNotWPError($advanced);
        $stale = $services['coordinator']->runNextChunk(
            $started['scan_ref'],
            $started['generation'],
            $started['revision']
        );
        $this->assertNotWPError($stale);
        $this->assertSame('stale', $stale['request_status']);
        $this->assertSame($advanced['revision'], $stale['revision']);
        $this->assertSame($advanced['progress']['processed'], $stale['progress']['processed']);
        $this->assertSame($advanced['groups'], $stale['groups']);

        $token = $services['store']->acquireLock($advanced['scan_ref']);
        $this->assertIsString($token);
        $busy = $services['coordinator']->runNextChunk(
            $advanced['scan_ref'],
            $advanced['generation'],
            $advanced['revision']
        );
        $this->assertWPError($busy);
        $this->assertSame('media_scan_busy', $busy->get_error_code());
        $services['store']->releaseLock($advanced['scan_ref'], $token);

        $wrong_generation = $services['coordinator']->runNextChunk(
            $advanced['scan_ref'],
            'vmsg_wronggeneration',
            $advanced['revision']
        );
        $this->assertWPError($wrong_generation);
        $this->assertSame('media_scan_generation_mismatch', $wrong_generation->get_error_code());
    }

    public function test_snapshots_are_user_and_blog_bound_cancelable_replaceable_and_expiring(): void
    {
        $this->seedMediaEntities();
        $services = $this->services(2, 2);
        $first = $services['coordinator']->start();
        $this->assertNotWPError($first);
        $this->rememberScan($first);

        $other_admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($other_admin);
        $cross_user = $services['store']->load($first['scan_ref']);
        $this->assertWPError($cross_user);
        $this->assertSame('media_scan_expired_or_invalid', $cross_user->get_error_code());

        wp_set_current_user($this->administrator_id);
        $second = $services['coordinator']->start();
        $this->assertNotWPError($second);
        $this->rememberScan($second);
        $this->assertNotSame($first['scan_ref'], $second['scan_ref']);
        $replaced = $services['store']->load($first['scan_ref']);
        $this->assertNotWPError($replaced);
        $this->assertSame('canceled', $replaced['state']);
        $this->assertSame('replaced', $replaced['last_error']['code']);

        $canceled = $services['coordinator']->cancel(
            $second['scan_ref'],
            $second['generation'],
            $second['revision']
        );
        $this->assertNotWPError($canceled);
        $this->assertSame('canceled', $canceled['state']);
        $this->assertSame('canceled', $canceled['request_status']);

        $third = $services['coordinator']->start();
        $this->assertNotWPError($third);
        $this->rememberScan($third);
        $transient_key = 'dbvc_visual_editor_media_scan_' . $third['scan_ref'];
        $payload = get_transient($transient_key);
        $this->assertIsArray($payload);
        $payload['blog_id'] = get_current_blog_id() + 1;
        set_transient($transient_key, $payload, 300);
        $cross_blog = $services['store']->load($third['scan_ref']);
        $this->assertWPError($cross_blog);

        $payload['blog_id'] = get_current_blog_id();
        $payload['expires_at'] = time() - 1;
        set_transient($transient_key, $payload, 300);
        $expired = $services['store']->load($third['scan_ref']);
        $this->assertWPError($expired);
        $this->assertSame('media_scan_expired_or_invalid', $expired->get_error_code());
    }

    public function test_configuration_change_invalidates_generation_before_candidate_work(): void
    {
        $this->seedMediaEntities();
        $services = $this->services(2, 2);
        $snapshot = $services['coordinator']->start();
        $this->assertNotWPError($snapshot);
        $this->rememberScan($snapshot);

        $GLOBALS['dbvc_test_acf_fields']['group_scan_media'][0]['label'] = 'Changed image label';
        $invalidated = $services['coordinator']->runNextChunk(
            $snapshot['scan_ref'],
            $snapshot['generation'],
            $snapshot['revision']
        );
        $this->assertNotWPError($invalidated);
        $this->assertSame('invalidated', $invalidated['state']);
        $this->assertSame('configuration_changed', $invalidated['last_error']['code']);
        $this->assertSame(0, $invalidated['progress']['processed']);
    }

    public function test_retry_resumes_the_same_cursor_after_a_retryable_provider_failure(): void
    {
        $this->seedMediaEntities();
        $services = $this->services(2, 2);
        $snapshot = $services['coordinator']->start();
        $this->assertNotWPError($snapshot);
        $this->rememberScan($snapshot);
        $original_cursor = $snapshot['cursor'];

        $GLOBALS['dbvc_test_acf_value_reader'] = static function () {
            throw new RuntimeException('synthetic provider failure');
        };
        $failed = $services['coordinator']->runNextChunk(
            $snapshot['scan_ref'],
            $snapshot['generation'],
            $snapshot['revision']
        );
        $this->assertNotWPError($failed);
        $this->assertSame('failed', $failed['state']);
        $this->assertTrue($failed['last_error']['retryable']);
        $this->assertSame($original_cursor, $failed['cursor']);
        $this->assertSame(0, $failed['progress']['processed']);

        unset($GLOBALS['dbvc_test_acf_value_reader']);
        $retrying = $services['coordinator']->retry(
            $failed['scan_ref'],
            $failed['generation'],
            $failed['revision']
        );
        $this->assertNotWPError($retrying);
        $this->assertSame('scanning', $retrying['state']);
        $this->assertSame(1, $retrying['progress']['retry_count']);
        $this->assertSame($original_cursor, $retrying['cursor']);

        $next = $services['coordinator']->runNextChunk(
            $retrying['scan_ref'],
            $retrying['generation'],
            $retrying['revision']
        );
        $this->assertNotWPError($next);
        $this->assertNotSame('failed', $next['state']);
        $this->assertGreaterThan(0, $next['progress']['processed']);
    }

    /**
     * @param int $chunk_size
     * @param int $source_budget
     * @return array<string, object>
     */
    private function services($chunk_size, $source_budget)
    {
        $policy = new \Dbvc\VisualEditor\MediaManager\EligibilityPolicy(
            new \Dbvc\VisualEditor\Permissions\CapabilityManager()
        );
        $catalog = new \Dbvc\VisualEditor\MediaManager\AcfMediaFieldCatalog($policy);
        $provider = new \Dbvc\VisualEditor\MediaManager\ScanCandidateProvider($policy, $source_budget);
        $store = new \Dbvc\VisualEditor\MediaManager\ScanSnapshotStore(600, 1048576);
        $scanner = new \Dbvc\VisualEditor\MediaManager\MediaScanService(
            $catalog,
            new \Dbvc\VisualEditor\MediaManager\MediaAssignmentValueClassifier(),
            static function ($selector, $object_id) {
                $callback = $GLOBALS['dbvc_test_acf_value_reader'] ?? null;
                if (is_callable($callback)) {
                    return call_user_func($callback, $selector, $object_id);
                }

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

        return compact('catalog', 'provider', 'store', 'scanner', 'coordinator');
    }

    /**
     * @return array<string, int>
     */
    private function seedMediaEntities()
    {
        $empty_post = self::factory()->post->create([
            'post_type' => 'dbvc_mm_scan',
            'post_status' => 'publish',
            'post_title' => 'Empty media post',
        ]);
        $gallery_only_post = self::factory()->post->create([
            'post_type' => 'dbvc_mm_scan',
            'post_status' => 'publish',
            'post_title' => 'Gallery-only finding',
        ]);
        $invalid_post = self::factory()->post->create([
            'post_type' => 'dbvc_mm_scan',
            'post_status' => 'publish',
            'post_title' => 'Invalid legacy image',
        ]);
        self::factory()->post->create([
            'post_type' => 'dbvc_mm_scan',
            'post_status' => 'draft',
            'post_title' => 'Excluded draft',
        ]);
        $term = self::factory()->term->create_and_get([
            'taxonomy' => 'dbvc_mm_scan_tax',
            'name' => 'Empty media term',
        ]);

        update_post_meta($gallery_only_post, '_thumbnail_id', 99);
        $GLOBALS['dbvc_test_acf_values'] = [
            $empty_post . '|field_scan_image' => null,
            $empty_post . '|field_scan_media_group' => ['gallery' => []],
            $gallery_only_post . '|field_scan_image' => 88,
            $gallery_only_post . '|field_scan_media_group' => ['gallery' => []],
            $invalid_post . '|field_scan_image' => 'legacy-image-url.jpg',
            $invalid_post . '|field_scan_media_group' => ['gallery' => [77]],
            'dbvc_mm_scan_tax_' . $term->term_id . '|field_scan_image' => null,
            'dbvc_mm_scan_tax_' . $term->term_id . '|field_scan_media_group' => ['gallery' => []],
        ];

        return [
            'empty_post' => $empty_post,
            'gallery_only_post' => $gallery_only_post,
            'invalid_post' => $invalid_post,
            'term' => $term->term_id,
        ];
    }

    /**
     * @return void
     */
    private function seedAcfDefinitions(): void
    {
        $GLOBALS['dbvc_test_acf_groups'] = [
            [
                'key' => 'group_scan_media',
                'title' => 'Scan Media',
                'active' => true,
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'dbvc_mm_scan']]],
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
                [
                    'key' => 'field_scan_conditional',
                    'name' => 'conditional_image',
                    'label' => 'Conditional image',
                    'type' => 'image',
                    'conditional_logic' => [[['field' => 'field_toggle', 'operator' => '==', 'value' => '1']]],
                ],
            ],
        ];
        $GLOBALS['dbvc_test_acf_group_visibility'] = static function ($group, $screen) {
            if (($group['key'] ?? '') !== 'group_scan_media') {
                return false;
            }

            return ($screen['post_type'] ?? '') === 'dbvc_mm_scan'
                || ($screen['taxonomy'] ?? '') === 'dbvc_mm_scan_tax';
        };
        $GLOBALS['dbvc_test_acf_values'] = [];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return void
     */
    private function rememberScan(array $snapshot)
    {
        if (! empty($snapshot['scan_ref'])) {
            $this->scan_refs[] = (string) $snapshot['scan_ref'];
        }
    }
}
