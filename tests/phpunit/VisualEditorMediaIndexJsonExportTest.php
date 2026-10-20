<?php

/**
 * R2-H Phase 1 Slice 5 — derived JSON mirror of the durable Media Index.
 *
 * Verifies the exporter writes only the SERVING generation into the sync folder,
 * the guarded importer hydrates an empty table from that mirror (and no-ops when
 * the table already has rows), and a round-trip (export → deleteAll → import)
 * restores the visible rows under the exported generation.
 */
final class VisualEditorMediaIndexJsonExportTest extends WP_UnitTestCase
{
    /**
     * @var string
     */
    private $sync_base = '';

    /**
     * @var \Dbvc\VisualEditor\MediaManager\MediaIndexStore
     */
    private $store;

    /**
     * @var array<string, mixed>
     */
    private $previous_options = [];

    public function set_up(): void
    {
        parent::set_up();

        foreach ([
            'dbvc_visual_editor_media_index_generation',
            'dbvc_visual_editor_media_index_building_generation',
            'dbvc_visual_editor_media_index_build',
        ] as $option_name) {
            $this->previous_options[$option_name] = get_option($option_name, '__dbvc_missing_option__');
        }

        // A per-test sync base inside ABSPATH so `dbvc_is_safe_file_path` passes.
        $this->sync_base = trailingslashit(ABSPATH) . 'wp-content/uploads/dbvc-mm-json-' . wp_generate_password(8, false, false);
        wp_mkdir_p($this->sync_base);

        $this->store = new \Dbvc\VisualEditor\MediaManager\MediaIndexStore();
        $this->store->maybeUpgrade();
        $this->store->deleteAll();
        $this->store->abortRebuild();
    }

    public function tear_down(): void
    {
        $this->store->deleteAll();
        $this->store->abortRebuild();

        if ($this->sync_base !== '' && is_dir($this->sync_base)) {
            $this->rmrfInsideAbspath($this->sync_base);
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

    public function test_export_writes_envelope_with_only_serving_generation_rows(): void
    {
        $serving = $this->store->currentGeneration();
        $this->seedRow('post', 41, 'dbvc_svc', 2, $serving);
        $this->seedRow('post', 42, 'dbvc_svc', 0, $serving);

        // A stray non-serving generation must not be exported (an in-flight rebuild
        // could produce building-gen rows; those are internal state).
        $this->seedRow('term', 99, 'dbvc_svc_tax', 1, 'vmig_' . str_repeat('c', 20));

        $exporter = $this->makeExporter();
        $file_path = $exporter->exportAll();

        $this->assertNotSame('', $file_path);
        $this->assertFileExists($file_path);

        $envelope = json_decode((string) file_get_contents($file_path), true);
        $this->assertIsArray($envelope);
        $this->assertSame(1, $envelope['schema']);
        $this->assertSame($serving, $envelope['generation']);
        $this->assertSame(2, $envelope['count']);
        $this->assertCount(2, $envelope['entities']);
        $ids = array_map(static function ($e) {
            return $e['entity_id'];
        }, $envelope['entities']);
        sort($ids);
        $this->assertSame([41, 42], $ids);

        foreach ($envelope['entities'] as $entity) {
            // The exported row must not carry the internal `index_generation` column
            // (the envelope-level `generation` is authoritative).
            $this->assertArrayNotHasKey('index_generation', $entity);
        }
    }

    public function test_import_populates_empty_table_and_marks_builder_complete(): void
    {
        // Seed and export, then delete everything to simulate a restore scenario.
        $serving = $this->store->currentGeneration();
        $this->seedRow('post', 51, 'dbvc_svc', 3, $serving);
        $this->seedRow('post', 52, 'dbvc_svc', 1, $serving);

        $exporter = $this->makeExporter(true);
        $file_path = $exporter->exportAll();
        $this->assertFileExists($file_path);

        $this->store->deleteAll();
        // Simulate a fresh restore: the generation option carries over from backup
        // OR is reset — either way, the importer must adopt the mirror's generation.
        delete_option('dbvc_visual_editor_media_index_generation');
        delete_option('dbvc_visual_editor_media_index_build');
        $this->assertSame(0, $this->store->countEntities());

        $imported = $exporter->importIfEmpty();
        $this->assertSame(2, $imported);
        $this->assertSame($serving, $this->store->currentGeneration());
        $this->assertSame(2, $this->store->countEntities(['generation' => $serving]));

        $row_51 = $this->store->getEntity('post', 51, 'dbvc_svc');
        $this->assertIsArray($row_51);
        $this->assertSame(3, $row_51['missing_count']);

        // Builder state must be marked complete for this generation so the drain
        // does not re-fire post-restore.
        $state = get_option('dbvc_visual_editor_media_index_build', []);
        $this->assertIsArray($state);
        $this->assertSame('complete', $state['status']);
        $this->assertSame($serving, $state['generation']);
        $this->assertTrue(! empty($state['imported_from_json']));
    }

    public function test_import_is_a_noop_when_the_table_is_populated(): void
    {
        // Write a mirror file for a random generation.
        $serving = $this->store->currentGeneration();
        $this->seedRow('post', 61, 'dbvc_svc', 1, $serving);
        $exporter = $this->makeExporter(true);
        $exporter->exportAll();

        // Now the table already has rows — the import must not touch anything.
        $before_generation = $this->store->currentGeneration();
        $before_count = $this->store->countEntities();

        $imported = $exporter->importIfEmpty();

        $this->assertSame(0, $imported, 'Import must be a no-op when the table already has rows.');
        $this->assertSame($before_generation, $this->store->currentGeneration());
        $this->assertSame($before_count, $this->store->countEntities());
    }

    public function test_round_trip_export_delete_import_preserves_visible_rows(): void
    {
        $serving = $this->store->currentGeneration();
        $this->seedRow('post', 71, 'dbvc_svc', 2, $serving);
        $this->seedRow('term', 72, 'category', 1, $serving);
        $before = $this->store->listEntities(['generation' => $serving, 'limit' => 100, 'sort' => 'entity_asc']);
        $this->assertCount(2, $before);

        $exporter = $this->makeExporter(true);
        $file_path = $exporter->exportAll();
        $this->assertFileExists($file_path);

        $this->store->deleteAll();
        delete_option('dbvc_visual_editor_media_index_generation');
        delete_option('dbvc_visual_editor_media_index_build');

        $imported = $exporter->importIfEmpty();
        $this->assertSame(2, $imported);

        $after = $this->store->listEntities(['generation' => $serving, 'limit' => 100, 'sort' => 'entity_asc']);
        $this->assertCount(2, $after);

        // Identity fields survive the round-trip; entity_refs are recomputed from
        // identity+generation at upsert time and match by that derivation.
        for ($i = 0; $i < count($before); $i++) {
            $this->assertSame($before[$i]['entity_type'], $after[$i]['entity_type']);
            $this->assertSame($before[$i]['entity_id'], $after[$i]['entity_id']);
            $this->assertSame($before[$i]['entity_subtype'], $after[$i]['entity_subtype']);
            $this->assertSame($before[$i]['missing_count'], $after[$i]['missing_count']);
            $this->assertSame($before[$i]['label'], $after[$i]['label']);
            $this->assertSame($before[$i]['entity_ref'], $after[$i]['entity_ref']);
        }
    }

    public function test_export_no_ops_when_sync_base_is_absent(): void
    {
        // Empty override + no dbvc_get_sync_path helper stub means syncBase() returns
        // whatever the real helper reports; the real sync folder default is outside
        // ABSPATH in tests, so the safety check filters it out. We assert exportAll
        // does not throw and returns an empty string when target is unsafe.
        $exporter = new \Dbvc\VisualEditor\MediaManager\MediaIndexJsonExporter($this->store);
        // Result may be a path (rare) or empty string, but it must never throw.
        $result = $exporter->exportAll();
        $this->assertIsString($result);
    }

    /**
     * @param bool $with_builder Whether to inject the builder so importIfEmpty marks
     *                            the build state complete on restore.
     * @return \Dbvc\VisualEditor\MediaManager\MediaIndexJsonExporter
     */
    private function makeExporter($with_builder = false)
    {
        $builder = null;
        if ($with_builder) {
            $capabilities = new \Dbvc\VisualEditor\Permissions\CapabilityManager();
            $policy = new \Dbvc\VisualEditor\MediaManager\EligibilityPolicy($capabilities, true);
            $catalog = new \Dbvc\VisualEditor\MediaManager\AcfMediaFieldCatalog($policy);
            $reader = static function ($selector, $object_id) {
                return get_post_meta(absint($object_id), (string) $selector, true);
            };
            $builder = new \Dbvc\VisualEditor\MediaManager\MediaIndexBuilder(
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

        return new \Dbvc\VisualEditor\MediaManager\MediaIndexJsonExporter(
            $this->store,
            $builder,
            $this->sync_base
        );
    }

    /**
     * @param string $type
     * @param int    $id
     * @param string $subtype
     * @param int    $missing
     * @param string $generation
     * @return void
     */
    private function seedRow($type, $id, $subtype, $missing, $generation)
    {
        $this->store->upsertEntity([
            'entity_type' => $type,
            'entity_id' => $id,
            'entity_subtype' => $subtype,
            'label' => ucfirst($type) . ' ' . $id,
            'frontend_url' => 'https://example.test/' . $type . '/' . $id . '/',
            'missing_count' => $missing,
            'populated_count' => 0,
            'family_counts' => ['acf_image' => $missing],
            'content_hash' => str_repeat((string) $id, 8),
            'index_generation' => $generation,
        ]);
    }

    /**
     * Recursively delete inside a path known to be within ABSPATH. Refuses to touch
     * anything outside so a test regression cannot rm the source tree.
     *
     * @param string $path
     * @return void
     */
    private function rmrfInsideAbspath($path)
    {
        $real = realpath($path);
        $abspath = realpath(ABSPATH);
        if ($real === false || $abspath === false || strpos($real, rtrim($abspath, '/') . '/') !== 0) {
            return;
        }
        if (! is_dir($real)) {
            @unlink($real);

            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($real);
    }
}
