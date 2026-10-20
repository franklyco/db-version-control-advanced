<?php

/**
 * R2-H Phase 1 Slice 1 — Media Index storage layer.
 *
 * Verifies the durable per-entity index store: schema creation, upsert
 * (insert then in-place update on the same entity identity), retrieval,
 * filtered listing/count, dirty marking, generation handling, and deletion.
 * This slice adds storage only — no Media Manager read/write path uses it yet.
 */
final class VisualEditorMediaIndexStoreTest extends WP_UnitTestCase
{
    /**
     * @var \Dbvc\VisualEditor\MediaManager\MediaIndexStore
     */
    private $store;

    public function set_up(): void
    {
        parent::set_up();
        $this->store = new \Dbvc\VisualEditor\MediaManager\MediaIndexStore();
        $this->store->maybeUpgrade();
        $this->store->deleteAll();
    }

    public function tear_down(): void
    {
        $this->store->deleteAll();
        parent::tear_down();
    }

    public function test_table_exists_and_is_queryable_after_upgrade(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'dbvc_ve_media_field_index';
        // A non-existent table makes get_var return null and sets last_error; an
        // existing empty table returns 0 with no error.
        $wpdb->last_error = '';
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $this->assertSame('', (string) $wpdb->last_error);
        $this->assertNotNull($count);
        $this->assertSame(0, (int) $count);
    }

    public function test_upsert_inserts_then_updates_the_same_entity_in_place(): void
    {
        $generation = $this->store->currentGeneration();

        $first_id = $this->store->upsertEntity([
            'entity_type' => 'post',
            'entity_id' => 42,
            'entity_subtype' => 'dbvc_svc',
            'entity_ref' => 'vemg_1111111111111111aaaa',
            'label' => 'Agencies',
            'frontend_url' => 'https://example.test/agencies/',
            'missing_count' => 2,
            'populated_count' => 1,
            'family_counts' => ['featured_image' => 1, 'acf_image' => 1],
            'content_hash' => str_repeat('a', 40),
            'index_generation' => $generation,
        ]);
        $this->assertGreaterThan(0, $first_id);

        // Same entity identity → in-place update, not a second row.
        $second_id = $this->store->upsertEntity([
            'entity_type' => 'post',
            'entity_id' => 42,
            'entity_subtype' => 'dbvc_svc',
            'entity_ref' => 'vemg_1111111111111111aaaa',
            'label' => 'Agencies',
            'frontend_url' => 'https://example.test/agencies/',
            'missing_count' => 0,
            'populated_count' => 3,
            'family_counts' => [],
            'content_hash' => str_repeat('b', 40),
            'index_generation' => $generation,
        ]);
        $this->assertSame($first_id, $second_id);
        $this->assertSame(1, $this->store->countEntities());

        $row = $this->store->getEntity('post', 42, 'dbvc_svc');
        $this->assertIsArray($row);
        $this->assertSame(0, $row['missing_count']);
        $this->assertSame(3, $row['populated_count']);
        $this->assertSame(str_repeat('b', 40), $row['content_hash']);
        $this->assertFalse($row['is_dirty']);
    }

    public function test_list_and_count_filter_by_missing_dirty_and_generation(): void
    {
        $generation = $this->store->currentGeneration();
        $this->seed('post', 1, 'page', 3, $generation);   // missing
        $this->seed('post', 2, 'page', 0, $generation);   // fully populated
        $this->seed('term', 9, 'category', 1, 'vmig_othergenerationxxxx'); // other generation

        $this->assertSame(3, $this->store->countEntities());
        $this->assertSame(2, $this->store->countEntities(['generation' => $generation]));
        $this->assertSame(1, $this->store->countEntities(['generation' => $generation, 'onlyMissing' => true]));

        $missing = $this->store->listEntities(['generation' => $generation, 'onlyMissing' => true]);
        $this->assertCount(1, $missing);
        $this->assertSame(1, $missing[0]['entity_id']);
        $this->assertSame(3, $missing[0]['missing_count']);

        // Pagination is bounded.
        $page = $this->store->listEntities(['generation' => $generation, 'limit' => 1, 'offset' => 0]);
        $this->assertCount(1, $page);
    }

    public function test_mark_dirty_targets_one_entity_and_a_whole_generation(): void
    {
        $generation = $this->store->currentGeneration();
        $this->seed('post', 1, 'page', 1, $generation);
        $this->seed('post', 2, 'page', 1, $generation);

        $this->assertSame(1, $this->store->markDirty('post', 1, 'page'));
        $this->assertTrue($this->store->getEntity('post', 1, 'page')['is_dirty']);
        $this->assertFalse($this->store->getEntity('post', 2, 'page')['is_dirty']);
        $this->assertSame(1, $this->store->countEntities(['onlyDirty' => true]));

        $this->store->markGenerationDirty($generation);
        $this->assertSame(2, $this->store->countEntities(['onlyDirty' => true]));
    }

    public function test_delete_and_prune_generations(): void
    {
        $current = $this->store->currentGeneration();
        $this->seed('post', 1, 'page', 1, $current);
        $this->seed('post', 2, 'page', 1, 'vmig_stalegenerationxxxx');

        $this->assertSame(1, $this->store->deleteEntity('post', 1, 'page'));
        $this->assertNull($this->store->getEntity('post', 1, 'page'));

        $this->seed('post', 3, 'page', 1, $current);
        // Pruning keeps only the current generation.
        $this->assertSame(1, $this->store->pruneOtherGenerations($current));
        $this->assertSame(1, $this->store->countEntities());
        $this->assertSame(1, $this->store->countEntities(['generation' => $current]));
    }

    public function test_rotate_generation_produces_a_new_opaque_generation(): void
    {
        $first = $this->store->currentGeneration();
        $this->assertMatchesRegularExpression('/^vmig_[a-f0-9]{20}$/', $first);
        $rotated = $this->store->rotateGeneration();
        $this->assertMatchesRegularExpression('/^vmig_[a-f0-9]{20}$/', $rotated);
        $this->assertNotSame($first, $rotated);
        $this->assertSame($rotated, $this->store->currentGeneration());
    }

    /**
     * @param string $type
     * @param int    $id
     * @param string $subtype
     * @param int    $missing
     * @param string $generation
     * @return void
     */
    private function seed($type, $id, $subtype, $missing, $generation)
    {
        $this->store->upsertEntity([
            'entity_type' => $type,
            'entity_id' => $id,
            'entity_subtype' => $subtype,
            'entity_ref' => 'vemg_' . str_pad((string) $id, 20, '0', STR_PAD_LEFT),
            'label' => ucfirst($type) . ' ' . $id,
            'missing_count' => $missing,
            'populated_count' => 1,
            'family_counts' => ['acf_image' => $missing],
            'content_hash' => str_repeat((string) $id, 8),
            'index_generation' => $generation,
        ]);
    }
}
