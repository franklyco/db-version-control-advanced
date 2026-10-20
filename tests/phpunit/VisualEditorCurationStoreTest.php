<?php

/**
 * R3-BX — CurationStore persistence contract.
 *
 * Covers: canonical id sanitization + rejection of malformed ids,
 * decision-value whitelist enforcement, meta stamping on non-empty
 * writes, empty-decision pruning, bulk write, summarize counts, and
 * isolation (writes touch only the dedicated option).
 */
final class VisualEditorCurationStoreTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        delete_option(\Dbvc\VisualEditor\Curation\CurationStore::OPTION_KEY);
    }

    public function tear_down(): void
    {
        delete_option(\Dbvc\VisualEditor\Curation\CurationStore::OPTION_KEY);
        parent::tear_down();
    }

    public function test_setDecision_writes_and_reads_back_a_normalized_decision(): void
    {
        $store = new \Dbvc\VisualEditor\Curation\CurationStore();
        $this->assertTrue($store->setDecision('option:site-settings:tagline', [
            'decision' => 'include',
            'category' => 'Brand',
            'client_priority' => 'must',
            'notes' => "must have\nsecond line",
        ]));

        $decision = $store->getDecision('option:site-settings:tagline');
        $this->assertSame('include', $decision['decision']);
        $this->assertSame('Brand', $decision['category']);
        $this->assertSame('must', $decision['client_priority']);
        $this->assertGreaterThan(0, (int) $decision['decided_at']);
        // sanitize_textarea_field preserves newlines but strips tags.
        $this->assertStringContainsString('must have', $decision['notes']);
    }

    public function test_malformed_ids_are_rejected(): void
    {
        $store = new \Dbvc\VisualEditor\Curation\CurationStore();
        $this->assertFalse($store->setDecision('', ['decision' => 'include']));
        $this->assertFalse($store->setDecision('not-canonical', ['decision' => 'include']));
        $this->assertFalse($store->setDecision('post:whatever:foo', ['decision' => 'include']));

        $this->assertSame([], $store->getAll());
    }

    public function test_invalid_decision_values_are_dropped_to_empty(): void
    {
        $store = new \Dbvc\VisualEditor\Curation\CurationStore();
        $store->setDecision('option:site-settings:tagline', [
            'decision' => 'DROP_TABLE_users',
            'client_priority' => 'urgent',
        ]);

        // The record was pruned because everything normalized to empty and no other
        // fields carried values, so the decision map stays empty.
        $this->assertSame([], $store->getAll());
    }

    public function test_empty_decision_prunes_the_id_from_the_map(): void
    {
        $store = new \Dbvc\VisualEditor\Curation\CurationStore();
        $store->setDecision('option:site-settings:tagline', ['decision' => 'include']);
        $this->assertArrayHasKey('option:site-settings:tagline', $store->getAll());

        $store->setDecision('option:site-settings:tagline', [
            'decision' => '',
            'client_priority' => '',
            'category' => '',
            'group' => '',
            'notes' => '',
        ]);
        $this->assertSame([], $store->getAll());
    }

    public function test_set_decisions_per_id_writes_different_partials_in_one_call(): void
    {
        $store = new \Dbvc\VisualEditor\Curation\CurationStore();
        $result = $store->setDecisionsPerId([
            'option:site-settings:tagline' => ['client_priority' => 'must'],
            'option:advanced-settings:brand_color_palette>vertical_global_palette>colorPrimary' => ['client_priority' => 'must'],
            'option:site-settings:brand_content>mission' => ['client_priority' => 'should'],
            'BOGUS_ID' => ['client_priority' => 'nice'],   // malformed — skipped
            'option:site-settings:brand_content>bio' => 'not-an-array', // wrong type — skipped
        ]);

        $this->assertSame(3, $result['written']);
        $this->assertSame(2, $result['skipped']);

        $all = $store->getAll();
        $this->assertCount(3, $all);
        $this->assertSame('must', $all['option:site-settings:tagline']['client_priority']);
        $this->assertSame('must', $all['option:advanced-settings:brand_color_palette>vertical_global_palette>colorPrimary']['client_priority']);
        $this->assertSame('should', $all['option:site-settings:brand_content>mission']['client_priority']);
    }

    public function test_set_decisions_per_id_preserves_existing_decision_when_only_priority_is_written(): void
    {
        $store = new \Dbvc\VisualEditor\Curation\CurationStore();
        $store->setDecision('option:site-settings:tagline', [
            'decision' => 'include',
            'category' => 'Brand',
        ]);
        $store->setDecisionsPerId([
            'option:site-settings:tagline' => ['client_priority' => 'must'],
        ]);

        $decision = $store->getDecision('option:site-settings:tagline');
        $this->assertSame('include', $decision['decision']); // preserved
        $this->assertSame('Brand', $decision['category']);   // preserved
        $this->assertSame('must', $decision['client_priority']); // adopted
    }

    public function test_bulk_write_applies_partial_to_every_id(): void
    {
        $store = new \Dbvc\VisualEditor\Curation\CurationStore();
        $written = $store->setDecisionsBulk(
            [
                'option:site-settings:tagline',
                'option:advanced-settings:brand_color_palette>vertical_global_palette>colorPrimary',
                'BOGUS_ID',
            ],
            ['decision' => 'include', 'category' => 'Brand']
        );

        $this->assertSame(2, $written);
        $all = $store->getAll();
        $this->assertCount(2, $all);
        foreach ($all as $decision) {
            $this->assertSame('include', $decision['decision']);
            $this->assertSame('Brand', $decision['category']);
        }
    }

    public function test_summarize_counts_by_decision_state(): void
    {
        $store = new \Dbvc\VisualEditor\Curation\CurationStore();
        $store->setDecision('option:site-settings:tagline', ['decision' => 'include']);
        $store->setDecision('option:site-settings:business_name', ['decision' => 'ignore']);

        $candidates = [
            ['id' => 'option:site-settings:tagline'],
            ['id' => 'option:site-settings:business_name'],
            ['id' => 'option:site-settings:hours'],
        ];
        $summary = $store->summarize($candidates);
        $this->assertSame(3, $summary['total']);
        $this->assertSame(1, $summary['include']);
        $this->assertSame(1, $summary['ignore']);
        $this->assertSame(0, $summary['defer']);
        $this->assertSame(1, $summary['undecided']);
    }

    public function test_notes_are_truncated_at_max_length(): void
    {
        $store = new \Dbvc\VisualEditor\Curation\CurationStore();
        $store->setDecision('option:site-settings:tagline', [
            'decision' => 'include',
            'notes' => str_repeat('x', 700),
        ]);
        $decision = $store->getDecision('option:site-settings:tagline');
        $this->assertLessThanOrEqual(500, strlen($decision['notes']));
    }
}
