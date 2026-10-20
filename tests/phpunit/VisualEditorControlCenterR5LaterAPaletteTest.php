<?php

use Dbvc\VisualEditor\Curation\CurationStore;
use Dbvc\VisualEditor\Curation\CurationExporter;
use Dbvc\VisualEditor\Curation\FieldCurationRecommender;

/**
 * R5.later-a — palette grouping for Vertical color_picker records.
 *
 * Covers three layers:
 *
 *   1. `CurationStore` — new `palette_group_key` field is normalised via
 *      sanitize_key, capped at max length, and is meaningful on its own
 *      (isEmptyDecision does not prune a decision that carries ONLY a
 *      palette_group_key).
 *   2. `CurationExporter` — passes `palette_group_key` from decision to
 *      export record so the Vertical provider can consume it.
 *   3. `VF_Vertical_Control_Provider::getControls()` — dedupe-emits ONE
 *      synthetic palette parent (`meta.role='palette'` +
 *      `meta.childCount=N` + `status='unsupported'`) when ≥ 2
 *      color_picker records share a curated `palette_group_key`, and
 *      stamps `parentPublicId` on each leaf so R5.7-b's tree renderer
 *      groups them under one disclosure. Solo opt-ins, missing keys,
 *      and non-color-picker records fall through to a flat emission —
 *      exactly the pre-R5.later behaviour.
 *
 * Fixture strategy: uses TOP-LEVEL color_picker records (no `>` in
 * field_name) so `resolveStatus` short-circuits without consulting the
 * ACF parent chain. Palette grouping is orthogonal to nested-record
 * status, and R51B already covers the nested / group-only gate.
 */
final class VisualEditorControlCenterR5LaterAPaletteTest extends WP_UnitTestCase
{
    /**
     * @var string
     */
    private $vertical_provider_path;

    public function set_up(): void
    {
        parent::set_up();
        // __DIR__ = .../plugins/db-version-control-main/tests/phpunit
        // 4 dirname() calls → .../wp-content, then jump into themes/vertical.
        $this->vertical_provider_path = dirname(__DIR__, 4)
            . '/themes/vertical/functions/features/dbvc-visual-editor/includes/class-vf-vertical-control-provider.php';
        if (! is_readable($this->vertical_provider_path)) {
            $this->markTestSkipped(
                'Vertical theme file not present at: ' . $this->vertical_provider_path
            );
        }
        require_once $this->vertical_provider_path;
        if (! class_exists('VF_Vertical_Control_Provider')) {
            $this->markTestSkipped('VF_Vertical_Control_Provider not loadable.');
        }
        delete_option(CurationStore::OPTION_KEY);
    }

    public function tear_down(): void
    {
        delete_option(CurationStore::OPTION_KEY);
        parent::tear_down();
    }

    // ---------------------------------------------------------------------
    // CurationStore — palette_group_key normalisation + persistence
    // ---------------------------------------------------------------------

    public function test_curation_store_accepts_and_normalises_palette_group_key(): void
    {
        $store = new CurationStore();
        $store->setDecision('option:brand-settings:primary_color', [
            'decision' => 'include',
            'palette_group_key' => 'BRAND-Primary!',
        ]);
        $decision = $store->getDecision('option:brand-settings:primary_color');
        // sanitize_key lowercases + strips characters outside [a-z0-9_-].
        $this->assertSame('brand-primary', $decision['palette_group_key']);
    }

    public function test_curation_store_truncates_palette_group_key_at_max_length(): void
    {
        $store = new CurationStore();
        $store->setDecision('option:brand-settings:primary_color', [
            'decision' => 'include',
            'palette_group_key' => str_repeat('a', 80),
        ]);
        $decision = $store->getDecision('option:brand-settings:primary_color');
        $this->assertLessThanOrEqual(40, strlen($decision['palette_group_key']));
    }

    public function test_curation_store_palette_key_alone_is_not_pruned(): void
    {
        // A palette_group_key is a meaningful curation intent on its own
        // (grouping ≠ include/ignore); isEmptyDecision must not prune a
        // decision that carries ONLY the key.
        $store = new CurationStore();
        $store->setDecision('option:brand-settings:primary_color', [
            'palette_group_key' => 'brand_primary',
        ]);
        $this->assertArrayHasKey(
            'option:brand-settings:primary_color',
            $store->getAll()
        );
    }

    public function test_curation_store_empty_when_palette_key_and_all_other_fields_empty(): void
    {
        $store = new CurationStore();
        $store->setDecision('option:brand-settings:primary_color', ['decision' => 'include']);
        $this->assertArrayHasKey('option:brand-settings:primary_color', $store->getAll());
        $store->setDecision('option:brand-settings:primary_color', [
            'decision' => '',
            'palette_group_key' => '',
        ]);
        $this->assertSame([], $store->getAll());
    }

    // ---------------------------------------------------------------------
    // CurationExporter — passthrough
    // ---------------------------------------------------------------------

    public function test_exporter_passes_palette_group_key_to_records(): void
    {
        $export_dir = sys_get_temp_dir() . '/vf_r5later_export_' . uniqid();
        mkdir($export_dir);
        try {
            $exporter = new CurationExporter($export_dir, new FieldCurationRecommender());
            $candidates = [
                [
                    'id' => 'option:brand-settings:primary_color',
                    'field_type' => 'color_picker',
                    'field_label' => 'Primary',
                    'field_name_path' => 'primary_color',
                    'field_key' => 'field_primary_color',
                    'options_page' => 'brand-settings',
                    'group_title' => 'Brand Palette',
                    'ancestor_labels' => [],
                ],
            ];
            $decisions = [
                'option:brand-settings:primary_color' => [
                    'decision' => 'include',
                    'palette_group_key' => 'brand_primary',
                    'client_priority' => 'must',
                    'category' => 'Brand',
                    'group' => 'Brand Palette',
                    'notes' => '',
                ],
            ];
            $result = $exporter->export($candidates, $decisions);
            $this->assertTrue($result['ok']);
            $payload = json_decode((string) file_get_contents($result['json_path']), true);
            $this->assertIsArray($payload);
            $this->assertSame('brand_primary', $payload['records'][0]['palette_group_key']);
        } finally {
            @unlink($export_dir . '/' . CurationExporter::JSON_FILENAME);
            @unlink($export_dir . '/' . CurationExporter::MD_FILENAME);
            @rmdir($export_dir);
        }
    }

    public function test_exporter_omits_palette_group_key_as_empty_string_when_absent(): void
    {
        $export_dir = sys_get_temp_dir() . '/vf_r5later_export_' . uniqid();
        mkdir($export_dir);
        try {
            $exporter = new CurationExporter($export_dir, new FieldCurationRecommender());
            $candidates = [
                [
                    'id' => 'option:site-settings:tagline',
                    'field_type' => 'text',
                    'field_label' => 'Tagline',
                    'field_name_path' => 'tagline',
                    'field_key' => 'field_tagline',
                    'options_page' => 'site-settings',
                    'group_title' => '',
                    'ancestor_labels' => [],
                ],
            ];
            $decisions = [
                'option:site-settings:tagline' => [
                    'decision' => 'include',
                    'client_priority' => 'must',
                ],
            ];
            $result = $exporter->export($candidates, $decisions);
            $payload = json_decode((string) file_get_contents($result['json_path']), true);
            $this->assertSame('', $payload['records'][0]['palette_group_key']);
        } finally {
            @unlink($export_dir . '/' . CurationExporter::JSON_FILENAME);
            @unlink($export_dir . '/' . CurationExporter::MD_FILENAME);
            @rmdir($export_dir);
        }
    }

    public function test_exporter_falls_back_to_recommender_suggestion_when_decision_empty(): void
    {
        // R5.later-a.5: color_picker candidate nested inside a
        // "palette"-named parent group + decision has NO explicit
        // palette_group_key → exporter uses the recommender's
        // heuristic suggestion so the site's Global Palette activates
        // automatically without a per-row curator toggle.
        $export_dir = sys_get_temp_dir() . '/vf_r5latera5_export_' . uniqid();
        mkdir($export_dir);
        try {
            $exporter = new CurationExporter($export_dir, new FieldCurationRecommender());
            $candidates = [
                [
                    'id' => 'option:advanced-settings:brand_color_palette>vertical_global_palette>colorAccent',
                    'field_type' => 'color_picker',
                    'field_label' => 'Accent',
                    'field_name_path' => 'brand_color_palette>vertical_global_palette>colorAccent',
                    'field_key' => 'field_67e7b1a4275e7',
                    'options_page' => 'advanced-settings',
                    'group_title' => 'Site Settings Advanced',
                    'ancestor_labels' => ['Brand Color Palette', 'Vertical Global Palette'],
                ],
            ];
            $decisions = [
                'option:advanced-settings:brand_color_palette>vertical_global_palette>colorAccent' => [
                    'decision' => 'include',
                    'client_priority' => 'must',
                    // palette_group_key deliberately absent → auto-suggestion kicks in
                ],
            ];
            $result = $exporter->export($candidates, $decisions);
            $payload = json_decode((string) file_get_contents($result['json_path']), true);
            $this->assertSame('vertical_global_palette', $payload['records'][0]['palette_group_key']);
        } finally {
            @unlink($export_dir . '/' . CurationExporter::JSON_FILENAME);
            @unlink($export_dir . '/' . CurationExporter::MD_FILENAME);
            @rmdir($export_dir);
        }
    }

    public function test_exporter_curator_override_wins_over_recommender_suggestion(): void
    {
        // R5.later-a.5: same candidate as the previous test, but the
        // curator has explicitly set palette_group_key to a different
        // slug. Exporter must NOT overwrite the curator's choice with
        // the auto-suggestion — curator override always wins.
        $export_dir = sys_get_temp_dir() . '/vf_r5latera5_export_' . uniqid();
        mkdir($export_dir);
        try {
            $exporter = new CurationExporter($export_dir, new FieldCurationRecommender());
            $candidates = [
                [
                    'id' => 'option:advanced-settings:brand_color_palette>vertical_global_palette>colorAccent',
                    'field_type' => 'color_picker',
                    'field_label' => 'Accent',
                    'field_name_path' => 'brand_color_palette>vertical_global_palette>colorAccent',
                    'field_key' => 'field_67e7b1a4275e7',
                    'options_page' => 'advanced-settings',
                    'group_title' => 'Site Settings Advanced',
                    'ancestor_labels' => [],
                ],
            ];
            $decisions = [
                'option:advanced-settings:brand_color_palette>vertical_global_palette>colorAccent' => [
                    'decision' => 'include',
                    'palette_group_key' => 'brand_primary', // curator override
                ],
            ];
            $result = $exporter->export($candidates, $decisions);
            $payload = json_decode((string) file_get_contents($result['json_path']), true);
            $this->assertSame('brand_primary', $payload['records'][0]['palette_group_key']);
        } finally {
            @unlink($export_dir . '/' . CurationExporter::JSON_FILENAME);
            @unlink($export_dir . '/' . CurationExporter::MD_FILENAME);
            @rmdir($export_dir);
        }
    }

    // ---------------------------------------------------------------------
    // Vertical provider — palette parent emission + child linking
    // ---------------------------------------------------------------------

    public function test_two_color_pickers_sharing_key_emit_palette_parent_plus_two_leaves(): void
    {
        $records = [
            $this->paletteRecord('primary_color', 'Primary', 'brand_primary'),
            $this->paletteRecord('accent_color', 'Accent', 'brand_primary'),
        ];
        $items = $this->makeProviderWithRecords($records)->getControls();

        $this->assertCount(3, $items);
        // Parent is emitted immediately before its first child.
        $parent = $items[0];
        $this->assertSame('palette_brand_primary', $parent['id']);
        $this->assertSame('palette_parent', $parent['source']['role']);
        $this->assertSame('palette', $parent['meta']['role']);
        $this->assertSame(2, $parent['meta']['childCount']);
        // R5.later-y (2026-09-04) — palette parents flipped from
        // status='unsupported' to status='available' so the drawer can
        // render an Open button alongside R5.later-a's disclosure
        // triangle. `buildPaletteOverviewDescriptor` mints the
        // panel-side palette_grid descriptor when Open is clicked.
        $this->assertSame('available', $parent['status']);
        $this->assertSame('other', $parent['fieldFamily']);

        $leaves = array_values(array_filter($items, static function ($r) {
            return isset($r['source']['field_type'])
                && $r['source']['field_type'] === 'color_picker';
        }));
        $this->assertCount(2, $leaves);
        foreach ($leaves as $leaf) {
            $this->assertSame(
                'vertical:palette_brand_primary',
                $leaf['parentPublicId']
            );
        }
    }

    public function test_solo_color_picker_with_palette_key_stays_flat(): void
    {
        // Only one record carries the key → count < 2 → no palette
        // parent, no parentPublicId stamp on the leaf.
        $records = [
            $this->paletteRecord('primary_color', 'Primary', 'brand_primary'),
        ];
        $items = $this->makeProviderWithRecords($records)->getControls();

        $this->assertCount(1, $items);
        $this->assertSame('color_picker', $items[0]['source']['field_type']);
        $this->assertArrayNotHasKey('parentPublicId', $items[0]);
    }

    public function test_missing_palette_key_stays_flat(): void
    {
        $records = [
            $this->paletteRecord('primary_color', 'Primary', ''),
            $this->paletteRecord('accent_color', 'Accent', ''),
        ];
        $items = $this->makeProviderWithRecords($records)->getControls();

        $this->assertCount(2, $items);
        foreach ($items as $item) {
            $this->assertArrayNotHasKey('parentPublicId', $item);
        }
    }

    public function test_non_color_picker_with_palette_key_is_ignored(): void
    {
        // Palette grouping is color-only in MVP. A text record carrying
        // the same key contributes zero to the count and stays flat even
        // when two other color pickers share it.
        $records = [
            $this->paletteRecord('primary_color', 'Primary', 'brand_primary'),
            $this->paletteRecord('accent_color', 'Accent', 'brand_primary'),
            [
                'id' => 'brand__primary_note',
                'label' => 'Primary note',
                'field_name' => 'primary_note',
                'field_key' => 'field_primary_note',
                'field_type' => 'text',
                'unlocks_at' => 'R5.1',
                'category' => 'Brand',
                'group_title' => 'Brand Palette',
                'palette_group_key' => 'brand_primary',
            ],
        ];
        $items = $this->makeProviderWithRecords($records)->getControls();

        // 1 palette parent + 2 color leaves + 1 unrelated text = 4.
        $this->assertCount(4, $items);
        $parent = null;
        foreach ($items as $item) {
            if (($item['source']['role'] ?? '') === 'palette_parent') {
                $parent = $item;
                break;
            }
        }
        $this->assertNotNull($parent);
        $this->assertSame(2, $parent['meta']['childCount']);
        // Text record stays flat — no parentPublicId stamp on it.
        $text_record = null;
        foreach ($items as $item) {
            if (($item['source']['field_type'] ?? '') === 'text') {
                $text_record = $item;
                break;
            }
        }
        $this->assertNotNull($text_record);
        $this->assertArrayNotHasKey('parentPublicId', $text_record);
    }

    public function test_two_different_palette_keys_emit_two_parents(): void
    {
        $records = [
            $this->paletteRecord('primary_color', 'Primary', 'brand_primary'),
            $this->paletteRecord('accent_color', 'Accent', 'brand_primary'),
            $this->paletteRecord('warn_color', 'Warn', 'semantic'),
            $this->paletteRecord('error_color', 'Error', 'semantic'),
        ];
        $items = $this->makeProviderWithRecords($records)->getControls();

        $parents = array_values(array_filter($items, static function ($r) {
            return ($r['source']['role'] ?? '') === 'palette_parent';
        }));
        $this->assertCount(2, $parents);
        $counts = [];
        foreach ($parents as $p) {
            $counts[$p['source']['palette_group_key']] = $p['meta']['childCount'];
        }
        $this->assertSame(2, $counts['brand_primary']);
        $this->assertSame(2, $counts['semantic']);
    }

    public function test_palette_parent_label_uses_humanised_slug_regardless_of_mapped_group(): void
    {
        // R5.later-a.5 (2026-09-03) — palette parent label is now
        // always derived from the palette_group_key slug via
        // humanisation. mapRecord()'s `group` field is populated from
        // ACF's `group_title` (container's title, not a curator-set
        // palette display label), and inheriting it caused generic
        // container names like "Site Settings Advanced" to duplicate
        // the drawer's R4-C-2 category-header text above the palette.
        // Reverting to slug-only means the palette parent's identity
        // is always the palette itself. If curator override becomes
        // needed, a dedicated `palette_display_label` field can ship
        // in a future R5.later-a.6 slice.
        $records = [
            $this->paletteRecord('primary_color', 'Primary', 'brand_primary', 'Brand Palette'),
            $this->paletteRecord('accent_color', 'Accent', 'brand_primary', 'Brand Palette'),
        ];
        $items = $this->makeProviderWithRecords($records)->getControls();
        // Even though the seeded `group_title` is 'Brand Palette', the
        // parent label is now the humanised slug 'Brand Primary'.
        $this->assertSame('Brand Primary', $items[0]['label']);
    }

    public function test_palette_parent_label_falls_back_to_humanised_key(): void
    {
        $records = [
            $this->paletteRecord('primary_color', 'Primary', 'brand_primary', ''),
            $this->paletteRecord('accent_color', 'Accent', 'brand_primary', ''),
        ];
        $items = $this->makeProviderWithRecords($records)->getControls();
        $this->assertSame('Brand Primary', $items[0]['label']);
    }

    public function test_palette_parent_is_deduped_across_five_children(): void
    {
        $records = [];
        foreach (['primary', 'accent', 'secondary', 'text', 'surface'] as $name) {
            $records[] = $this->paletteRecord(
                $name . '_color',
                ucfirst($name),
                'brand_primary'
            );
        }
        $items = $this->makeProviderWithRecords($records)->getControls();

        $parents = array_values(array_filter($items, static function ($r) {
            return ($r['source']['role'] ?? '') === 'palette_parent';
        }));
        $this->assertCount(1, $parents);
        $this->assertSame(5, $parents[0]['meta']['childCount']);
        // All five leaves carry parentPublicId.
        $leaves = array_values(array_filter($items, static function ($r) {
            return isset($r['source']['field_type'])
                && $r['source']['field_type'] === 'color_picker';
        }));
        $this->assertCount(5, $leaves);
        foreach ($leaves as $leaf) {
            $this->assertSame(
                'vertical:palette_brand_primary',
                $leaf['parentPublicId']
            );
        }
    }

    public function test_palette_source_and_id_use_slug_form_of_key(): void
    {
        // Curator uses a mixed-case + hyphenated key. sanitize_key
        // lowercases + preserves hyphens; the parent id and source key
        // must both use the slug form so publicIds and lookups stay stable.
        $records = [
            $this->paletteRecord('primary_color', 'Primary', 'brand-Primary'),
            $this->paletteRecord('accent_color', 'Accent', 'brand-Primary'),
        ];
        $items = $this->makeProviderWithRecords($records)->getControls();
        $parent = $items[0];
        $this->assertSame('brand-primary', $parent['source']['palette_group_key']);
        $this->assertSame('palette_brand-primary', $parent['id']);
    }

    public function test_humanize_handles_multi_word_and_hyphenated_keys(): void
    {
        // Underscore-separated.
        $records_a = [
            $this->paletteRecord('a_color', 'A', 'brand_dark_mode', ''),
            $this->paletteRecord('b_color', 'B', 'brand_dark_mode', ''),
        ];
        $this->assertSame(
            'Brand Dark Mode',
            $this->makeProviderWithRecords($records_a)->getControls()[0]['label']
        );

        // Hyphen-separated.
        $records_b = [
            $this->paletteRecord('c_color', 'C', 'brand-warm', ''),
            $this->paletteRecord('d_color', 'D', 'brand-warm', ''),
        ];
        $this->assertSame(
            'Brand Warm',
            $this->makeProviderWithRecords($records_b)->getControls()[0]['label']
        );
    }

    // ---------------------------------------------------------------------
    // R5.later-a.6 — curator-set palette_display_label override
    // ---------------------------------------------------------------------

    public function test_curation_store_accepts_and_normalises_palette_display_label(): void
    {
        // sanitize_text_field preserves casing + spaces (unlike
        // sanitize_key on palette_group_key). A curator-typed
        // "  Brand Colors " lands as "Brand Colors" — trim-only, no
        // slug-flattening.
        $store = new CurationStore();
        $store->setDecision('option:brand-settings:primary_color', [
            'decision' => 'include',
            'palette_display_label' => '  Brand Colors  ',
        ]);
        $decision = $store->getDecision('option:brand-settings:primary_color');
        $this->assertSame('Brand Colors', $decision['palette_display_label']);
    }

    public function test_curation_store_truncates_palette_display_label_at_max_length(): void
    {
        $store = new CurationStore();
        $store->setDecision('option:brand-settings:primary_color', [
            'decision' => 'include',
            'palette_display_label' => str_repeat('a', 120),
        ]);
        $decision = $store->getDecision('option:brand-settings:primary_color');
        $this->assertLessThanOrEqual(60, strlen($decision['palette_display_label']));
    }

    public function test_curation_store_display_label_alone_is_not_pruned(): void
    {
        // Like palette_group_key, a display label on its own is a
        // meaningful curation intent (the curator wants the palette
        // parent to read a specific way) and must survive
        // normaliseDecision → isEmptyDecision.
        $store = new CurationStore();
        $store->setDecision('option:brand-settings:primary_color', [
            'palette_display_label' => 'Brand Colors',
        ]);
        $this->assertArrayHasKey(
            'option:brand-settings:primary_color',
            $store->getAll()
        );
    }

    public function test_exporter_passes_palette_display_label_to_records(): void
    {
        $export_dir = sys_get_temp_dir() . '/vf_r5latera6_export_' . uniqid();
        mkdir($export_dir);
        try {
            $exporter = new CurationExporter($export_dir, new FieldCurationRecommender());
            $candidates = [
                [
                    'id' => 'option:brand-settings:primary_color',
                    'field_type' => 'color_picker',
                    'field_label' => 'Primary',
                    'field_name_path' => 'primary_color',
                    'field_key' => 'field_primary_color',
                    'options_page' => 'brand-settings',
                    'group_title' => 'Brand Palette',
                    'ancestor_labels' => [],
                ],
            ];
            $decisions = [
                'option:brand-settings:primary_color' => [
                    'decision' => 'include',
                    'palette_group_key' => 'brand_primary',
                    'palette_display_label' => 'Brand Colors',
                ],
            ];
            $result = $exporter->export($candidates, $decisions);
            $this->assertTrue($result['ok']);
            $payload = json_decode((string) file_get_contents($result['json_path']), true);
            $this->assertSame('Brand Colors', $payload['records'][0]['palette_display_label']);
        } finally {
            @unlink($export_dir . '/' . CurationExporter::JSON_FILENAME);
            @unlink($export_dir . '/' . CurationExporter::MD_FILENAME);
            @rmdir($export_dir);
        }
    }

    public function test_exporter_omits_palette_display_label_as_empty_string_when_absent(): void
    {
        $export_dir = sys_get_temp_dir() . '/vf_r5latera6_export_' . uniqid();
        mkdir($export_dir);
        try {
            $exporter = new CurationExporter($export_dir, new FieldCurationRecommender());
            $candidates = [
                [
                    'id' => 'option:site-settings:tagline',
                    'field_type' => 'text',
                    'field_label' => 'Tagline',
                    'field_name_path' => 'tagline',
                    'field_key' => 'field_tagline',
                    'options_page' => 'site-settings',
                    'group_title' => '',
                    'ancestor_labels' => [],
                ],
            ];
            $decisions = [
                'option:site-settings:tagline' => [
                    'decision' => 'include',
                ],
            ];
            $result = $exporter->export($candidates, $decisions);
            $payload = json_decode((string) file_get_contents($result['json_path']), true);
            $this->assertSame('', $payload['records'][0]['palette_display_label']);
        } finally {
            @unlink($export_dir . '/' . CurationExporter::JSON_FILENAME);
            @unlink($export_dir . '/' . CurationExporter::MD_FILENAME);
            @rmdir($export_dir);
        }
    }

    public function test_palette_parent_uses_display_label_override_when_set(): void
    {
        // R5.later-a.6 — curator-set label wins over humanised slug.
        // The humanised slug of "vertical_global_palette" would be
        // "Vertical Global Palette"; setting palette_display_label to
        // "Brand Colors" replaces that entirely.
        $records = [
            $this->paletteRecord('primary_color', 'Primary', 'vertical_global_palette', 'Site Settings Advanced', 'Brand Colors'),
            $this->paletteRecord('accent_color', 'Accent', 'vertical_global_palette', 'Site Settings Advanced', 'Brand Colors'),
        ];
        $items = $this->makeProviderWithRecords($records)->getControls();
        $this->assertSame('Brand Colors', $items[0]['label']);
    }

    public function test_palette_parent_display_label_from_any_child_wins(): void
    {
        // Curator only needs to annotate ONE child in the palette group.
        // Here the FIRST record has no display label but the SECOND does;
        // the parent still picks it up from the pre-scan.
        $records = [
            $this->paletteRecord('primary_color', 'Primary', 'brand_primary', 'Brand Palette', ''),
            $this->paletteRecord('accent_color', 'Accent', 'brand_primary', 'Brand Palette', 'Curated Label'),
        ];
        $items = $this->makeProviderWithRecords($records)->getControls();
        $this->assertSame('Curated Label', $items[0]['label']);
    }

    public function test_palette_parent_first_non_empty_display_label_wins_across_children(): void
    {
        // If multiple children carry values, the first non-empty seen wins
        // (records iterate in the order they arrive from the exporter,
        // which mirrors curator-declared order). This makes the behaviour
        // deterministic and lets curators annotate the canonical color
        // (usually primary) instead of every row.
        $records = [
            $this->paletteRecord('primary_color', 'Primary', 'brand_primary', 'Brand Palette', 'First Label'),
            $this->paletteRecord('accent_color', 'Accent', 'brand_primary', 'Brand Palette', 'Second Label'),
        ];
        $items = $this->makeProviderWithRecords($records)->getControls();
        $this->assertSame('First Label', $items[0]['label']);
    }

    public function test_palette_parent_falls_back_to_humanised_slug_when_display_label_absent(): void
    {
        // No child carries a display_label → parent falls back to the
        // humanised slug. This is the R5.later-a.5 baseline behaviour and
        // must continue to work when the R5.later-a.6 field is unused.
        $records = [
            $this->paletteRecord('primary_color', 'Primary', 'brand_primary', 'Brand Palette', ''),
            $this->paletteRecord('accent_color', 'Accent', 'brand_primary', 'Brand Palette', ''),
        ];
        $items = $this->makeProviderWithRecords($records)->getControls();
        $this->assertSame('Brand Primary', $items[0]['label']);
    }

    public function test_palette_parent_whitespace_only_display_label_falls_back(): void
    {
        // A display label that is only whitespace (defensively — should
        // never happen since CurationStore's sanitize_text_field trims,
        // but the raw JSON could carry one from an older exporter) must
        // not become the parent's label — trim + fallback to humanised.
        $records = [
            $this->paletteRecord('primary_color', 'Primary', 'brand_primary', 'Brand Palette', '   '),
            $this->paletteRecord('accent_color', 'Accent', 'brand_primary', 'Brand Palette', '   '),
        ];
        $items = $this->makeProviderWithRecords($records)->getControls();
        $this->assertSame('Brand Primary', $items[0]['label']);
    }

    // ---------------------------------------------------------------------
    // R5.later-y — palette overview descriptor (panel palette grid mode)
    // ---------------------------------------------------------------------

    public function test_r5later_y_palette_parent_status_is_available(): void
    {
        // R5.later-y flipped palette parents from unsupported → available
        // so the drawer surfaces an Open button on them (alongside the
        // R5.later-a disclosure triangle). Guards against a regression
        // that would return them to unsupported and hide the panel entry
        // point.
        $records = [
            $this->paletteRecord('primary_color', 'Primary', 'brand_primary'),
            $this->paletteRecord('accent_color', 'Accent', 'brand_primary'),
        ];
        $items = $this->makeProviderWithRecords($records)->getControls();
        $this->assertSame('available', $items[0]['status']);
        $this->assertSame('palette_parent', $items[0]['source']['role']);
    }

    public function test_r5later_y_build_descriptor_mints_palette_grid_descriptor(): void
    {
        // Real ControlRecord construction runs through the registry's
        // fromArray path — we build a minimal ControlRecord that mirrors
        // what ControlRegistry emits for a palette_parent record.
        $records = [
            $this->paletteRecord('primary_color', 'Primary', 'brand_primary'),
            $this->paletteRecord('accent_color', 'Accent', 'brand_primary'),
        ];
        $provider = $this->makeProviderWithRecords($records);
        $items = $provider->getControls();
        // Locate the palette parent item — it's always emitted before
        // its first child (see the getControls loop's dedupe-emit).
        $parent_item = null;
        foreach ($items as $item) {
            if (($item['source']['role'] ?? '') === 'palette_parent') {
                $parent_item = $item;
                break;
            }
        }
        $this->assertNotNull($parent_item);
        // Reconstruct a ControlRecord — the provider's buildDescriptor
        // reads `source.palette_group_key` + `source.role`, `label`, and
        // `status`. The status must equal 'available' for the descriptor
        // to mint (see the early `!== 'available'` guard).
        $record = new \Dbvc\VisualEditor\Registry\ControlRecord(
            $parent_item['id'],
            'vertical',
            $parent_item['label'],
            'general',
            'palette',
            'option',
            isset($parent_item['ownerSubtype']) ? (string) $parent_item['ownerSubtype'] : '',
            'other',
            'available',
            $parent_item['source'],
            isset($parent_item['meta']) && is_array($parent_item['meta']) ? $parent_item['meta'] : [],
            null,
            isset($parent_item['description']) ? (string) $parent_item['description'] : '',
            isset($parent_item['sortKey']) ? (string) $parent_item['sortKey'] : ''
        );
        $descriptor = $provider->buildDescriptor($record, 'test-session-id', []);
        $this->assertNotNull($descriptor);
        $this->assertSame('available', $descriptor->status);
        $this->assertSame('current_entity', $descriptor->scope);
        $this->assertSame('palette_grid', $descriptor->render['input']);
        $this->assertSame('palette_parent', $descriptor->source['role']);
        $this->assertSame('brand_primary', $descriptor->source['palette_group_key']);
        $this->assertIsArray($descriptor->source['leaves']);
        $this->assertCount(2, $descriptor->source['leaves']);
        // Each leaf carries the required shape for the panel controller.
        foreach ($descriptor->source['leaves'] as $leaf) {
            $this->assertArrayHasKey('publicId', $leaf);
            $this->assertArrayHasKey('label', $leaf);
            $this->assertArrayHasKey('field_key', $leaf);
            $this->assertArrayHasKey('field_name', $leaf);
            $this->assertArrayHasKey('current_hex', $leaf);
            $this->assertStringStartsWith('vertical:', $leaf['publicId']);
        }
        // No mutation authority — palette overview is read-only.
        $this->assertEmpty($descriptor->mutation);
        $this->assertEmpty($descriptor->resolver);
        // R5.later-y-2b (2026-09-05) — regression guard for the "You cannot
        // edit that control." 403 seen in live QA. `CapabilityManager::canEditDescriptor`
        // only recognises `entity.type` of `post`/`term`/`user`/`option`;
        // anything else (`palette`, `container`, etc.) falls through to
        // `return false` and the open route returns 403. Palette parents
        // must present as `option`-scoped so the standard option capability
        // check (`manage_options` by default) applies. The `container`
        // discriminator preserves the "this is a palette container, not a
        // single option field" information for consumers that need it.
        $this->assertSame('option', $descriptor->entity['type']);
        $this->assertSame('palette', $descriptor->entity['container']);
        // R5.later-y-2c (2026-09-05) — regression guard for the "panel
        // renders a text input instead of the swatch grid" bug seen in
        // live QA. overlay-app.js's `renderEditorPanel` reads
        // `descriptor.ui.input` (NOT `descriptor.render.input`) to pick
        // the panel controller via `createFieldController`. Missing
        // `ui.input` caused the dispatch to fall through to the default
        // 'text' branch and render a plain text input. Both slots are
        // now populated with 'palette_grid': `render.input` so
        // `handleSave`'s early-return guard sees it, and `ui.input` so
        // the panel dispatch sees it. Test locks BOTH.
        $this->assertSame('palette_grid', $descriptor->ui['input']);
        $this->assertSame('palette_grid', $descriptor->render['input']);
        // R5.later-y-3 (2026-09-05) — regression guard for the
        // pre-minted leaf tokens. When leaves have valid ACF field
        // objects, each leaf entry in source.leaves should carry both
        // a `token` (non-empty string) and a `descriptor` (array —
        // EditableDescriptor::toArray output) so
        // ControlCenterOpenController can register the leaf in the
        // session at palette-parent open time. Client-side
        // descriptorCache then pre-warms with these tokens, dropping
        // first-save-per-swatch from 2 round-trips to 1. If get_field_object
        // isn't available in the test env, this branch soft-passes.
        foreach ($descriptor->source['leaves'] as $leaf) {
            if (function_exists('get_field_object') && ! empty($leaf['token'])) {
                $this->assertIsString($leaf['token']);
                $this->assertNotEmpty($leaf['token']);
                $this->assertArrayHasKey('descriptor', $leaf);
                $this->assertIsArray($leaf['descriptor']);
            }
        }
    }

    public function test_r5later_y_build_descriptor_returns_null_for_singleton_palette(): void
    {
        // A solo palette key never emits a palette parent in the first
        // place (count < 2 guard in getControls), so buildDescriptor
        // would never be called with a palette_parent record. Defensively
        // confirm that if a hand-crafted palette_parent record IS passed
        // in but no matching leaves exist in the cache, buildDescriptor
        // returns null rather than emitting a malformed empty-grid
        // descriptor. Guards against a curator's stale JSON exporting
        // a palette parent without matching children.
        $records = [
            $this->paletteRecord('primary_color', 'Primary', 'brand_primary'),
            $this->paletteRecord('accent_color', 'Accent', 'brand_primary'),
        ];
        $provider = $this->makeProviderWithRecords($records);
        // Preload records into the provider cache by calling getControls.
        $provider->getControls();
        // Craft a palette_parent record for a NON-EXISTENT palette key.
        $record = new \Dbvc\VisualEditor\Registry\ControlRecord(
            'palette_nonexistent',
            'vertical',
            'Nonexistent Palette',
            'general',
            'palette',
            'option',
            '',
            'other',
            'available',
            ['role' => 'palette_parent', 'palette_group_key' => 'nonexistent'],
            ['role' => 'palette'],
            null,
            '',
            ''
        );
        $descriptor = $provider->buildDescriptor($record, 'test-session-id', []);
        $this->assertNull($descriptor);
    }

    public function test_r5later_y_build_descriptor_returns_null_for_empty_palette_key(): void
    {
        $records = [
            $this->paletteRecord('primary_color', 'Primary', 'brand_primary'),
            $this->paletteRecord('accent_color', 'Accent', 'brand_primary'),
        ];
        $provider = $this->makeProviderWithRecords($records);
        $provider->getControls();
        $record = new \Dbvc\VisualEditor\Registry\ControlRecord(
            'palette_bogus',
            'vertical',
            'Bogus',
            'general',
            'palette',
            'option',
            '',
            'other',
            'available',
            ['role' => 'palette_parent', 'palette_group_key' => ''],
            [],
            null,
            '',
            ''
        );
        $this->assertNull($provider->buildDescriptor($record, 'test-session-id', []));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Build a curated color_picker record in the R3-BX export shape.
     * Top-level field_name (no `>`) so resolveStatus short-circuits
     * without an ACF parent-chain lookup — palette grouping is
     * orthogonal to nested-record status, which R51B already covers.
     *
     * @param string $field_name             Bare field name (no path).
     * @param string $label                  Human label.
     * @param string $palette_group_key      Curator's palette opt-in
     *                                        (empty = flat, no grouping).
     * @param string $group_title            Optional curator display label
     *                                        used as the palette parent's
     *                                        label when non-empty.
     * @param string $palette_display_label  R5.later-a.6 — optional curator
     *                                        label override for the palette
     *                                        parent's drawer label. Empty
     *                                        (default) leaves the parent to
     *                                        fall back to the humanised
     *                                        slug of $palette_group_key.
     * @return array<string, mixed>
     */
    private function paletteRecord(
        string $field_name,
        string $label,
        string $palette_group_key,
        string $group_title = 'Brand Palette',
        string $palette_display_label = ''
    ): array {
        return [
            'id' => 'brand__' . $field_name,
            'label' => $label,
            'field_name' => $field_name,
            'field_key' => 'field_' . $field_name,
            'field_type' => 'color_picker',
            'unlocks_at' => 'R5.2+color_picker',
            'category' => 'Brand',
            'group_title' => $group_title,
            'owner_subtype' => 'brand-settings',
            'client_priority' => 'must',
            'notes' => '',
            'palette_group_key' => $palette_group_key,
            'palette_display_label' => $palette_display_label,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function makeProviderWithRecords(array $records): \VF_Vertical_Control_Provider
    {
        $path = tempnam(sys_get_temp_dir(), 'vf_r5later_');
        file_put_contents(
            $path,
            (string) wp_json_encode(['records' => $records])
        );
        return new \VF_Vertical_Control_Provider(
            static function () use ($path) {
                return $path;
            }
        );
    }
}
