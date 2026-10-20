<?php

/**
 * R3-BX — Keyword-driven recommender (include/ignore/defer/review) plus
 * the unlocks_at derivation the exporter uses for the R5 sequencing summary.
 */
final class VisualEditorCurationRecommenderTest extends WP_UnitTestCase
{
    public function test_deferred_families_recommend_defer(): void
    {
        $rec = $this->recommender()->recommend($this->cand([
            'field_type' => 'repeater',
            'is_repeater_family' => true,
            'field_name_path' => 'hours',
            'field_label' => 'Hours',
        ]));
        $this->assertSame('defer', $rec['recommendation']);
        $this->assertSame('repeater-later', $rec['unlocks_at']);
    }

    public function test_operational_options_pages_default_to_ignore(): void
    {
        $rec = $this->recommender()->recommend($this->cand([
            'options_page' => 'integrations-settings',
            'field_type' => 'text',
            'field_name_path' => 'integration_google_analytics',
            'field_label' => 'GA Tracking ID',
        ]));
        $this->assertSame('ignore', $rec['recommendation']);
        $this->assertStringContainsString('integrations-settings', $rec['reasoning']);
    }

    public function test_ignore_keyword_beats_include_keyword_when_both_match(): void
    {
        // Contains "brand" (include) but also "gtm" (ignore) — ignore wins because
        // the operational keyword check runs first.
        $rec = $this->recommender()->recommend($this->cand([
            'options_page' => 'site-settings',
            'field_type' => 'text',
            'field_name_path' => 'brand_gtm_container_id',
            'field_label' => 'Brand GTM Container',
        ]));
        $this->assertSame('ignore', $rec['recommendation']);
    }

    public function test_brand_keyword_recommends_include_and_labels_category(): void
    {
        $rec = $this->recommender()->recommend($this->cand([
            'options_page' => 'site-settings',
            'field_type' => 'image',
            'field_name_path' => 'brand_elements>main_logo',
            'field_label' => 'Main Logo',
        ]));
        $this->assertSame('include', $rec['recommendation']);
        $this->assertSame('Brand', $rec['category']);
    }

    public function test_no_keyword_match_recommends_review(): void
    {
        $rec = $this->recommender()->recommend($this->cand([
            'options_page' => 'services-settings',
            'field_type' => 'text',
            'field_name_path' => 'zzz_uncategorized_slug',
            'field_label' => 'Uncategorized',
        ]));
        $this->assertSame('review', $rec['recommendation']);
        $this->assertSame('', $rec['category']);
    }

    public function test_recommend_priority_returns_must_for_core_brand_identity_keywords(): void
    {
        $r = $this->recommender();
        // Main logo — identity-critical.
        $must = $r->recommendPriority($this->cand([
            'field_name_path' => 'brand_elements>main_logo',
            'field_label' => 'Logo',
            'field_type' => 'image',
        ]));
        $this->assertSame('must', $must['priority']);
        $this->assertStringContainsString('must-tier', $must['reasoning']);

        // Core Vertical palette slot — identity-critical.
        $palette = $r->recommendPriority($this->cand([
            'field_name_path' => 'vertical_global_palette>colorPrimary',
            'field_label' => 'Primary',
            'field_type' => 'color_picker',
        ]));
        $this->assertSame('must', $palette['priority']);
    }

    public function test_recommend_priority_returns_should_for_common_brand_content(): void
    {
        $r = $this->recommender();
        $should = $r->recommendPriority($this->cand([
            'field_name_path' => 'brand_content>mission',
            'field_label' => 'Mission',
            'field_type' => 'textarea',
        ]));
        $this->assertSame('should', $should['priority']);
    }

    public function test_recommend_priority_returns_nice_for_stylistic_presets(): void
    {
        $r = $this->recommender();
        $nice = $r->recommendPriority($this->cand([
            'field_name_path' => 'settings_global_styles>style_master_preset',
            'field_label' => 'Global Style Profile',
            'field_type' => 'select',
        ]));
        $this->assertSame('nice', $nice['priority']);
    }

    public function test_recommend_priority_defaults_to_should_when_no_keyword_matches(): void
    {
        $r = $this->recommender();
        $default = $r->recommendPriority($this->cand([
            'field_name_path' => 'zzz_unknown_thing',
            'field_label' => 'Unknown',
            'field_type' => 'text',
        ]));
        $this->assertSame('should', $default['priority']);
        $this->assertStringContainsString('safe middle', $default['reasoning']);
    }

    public function test_recommend_priority_declines_when_primary_recommendation_is_ignore_or_defer(): void
    {
        $r = $this->recommender();
        // Operational page → primary recommendation is ignore → no priority.
        $ignored = $r->recommendPriority($this->cand([
            'options_page' => 'integrations-settings',
            'field_name_path' => 'ga4_measurement_id',
            'field_label' => 'GA4 ID',
            'field_type' => 'text',
        ]));
        $this->assertSame('', $ignored['priority']);

        // Repeater family → primary recommendation is defer → no priority.
        $deferred = $r->recommendPriority($this->cand([
            'field_name_path' => 'hours',
            'field_label' => 'Hours',
            'field_type' => 'repeater',
            'is_repeater_family' => true,
        ]));
        $this->assertSame('', $deferred['priority']);
    }

    public function test_unlocks_at_maps_families_to_the_right_r5_slice(): void
    {
        $r = $this->recommender();
        $this->assertSame('R5.1', $r->deriveUnlocksAt('text'));
        $this->assertSame('R5.2', $r->deriveUnlocksAt('select'));
        $this->assertSame('R5.2+color_picker', $r->deriveUnlocksAt('color_picker'));
        $this->assertSame('R5.3', $r->deriveUnlocksAt('image'));
        $this->assertSame('R5.4', $r->deriveUnlocksAt('post_object'));
        $this->assertSame('repeater-later', $r->deriveUnlocksAt('repeater'));
        $this->assertSame('later', $r->deriveUnlocksAt('some_totally_new_field_type'));
        // ACF `group` fields are structural containers, never emitted as
        // candidate rows by FieldCandidateProvider, so the recommender's map
        // deliberately has no `group` entry — falls through to `later`.
        $this->assertSame('later', $r->deriveUnlocksAt('group'));
        // R5.later Path B (2026-09-03): Vertical's `vf_palette` custom ACF
        // field type is permanently out-of-scope for the drawer (curator
        // already has a full per-term color-picker editor at Settings →
        // Site Settings Advanced via the native vf_palette input). The
        // `never` sentinel distinguishes "intentionally not supported" from
        // "not yet supported (pending R5.later work)" so the provider stays
        // `unsupported` without misleading maintainers into thinking it's
        // waiting on a future slice. See `docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/R5-REMAINING-UNLOCK-FRONTIERS.md` §R5.later.
        $this->assertSame('never', $r->deriveUnlocksAt('vf_palette'));
    }

    /**
     * @return \Dbvc\VisualEditor\Curation\FieldCurationRecommender
     */
    private function recommender()
    {
        return new \Dbvc\VisualEditor\Curation\FieldCurationRecommender();
    }

    // ---------------------------------------------------------------------
    // R5.later-a.5 — palette_group_key heuristic auto-suggestion
    // ---------------------------------------------------------------------

    public function test_suggests_palette_group_key_for_color_picker_in_palette_named_parent(): void
    {
        $r = $this->recommender();
        // The Vertical Global Palette's canonical shape:
        // `brand_color_palette>vertical_global_palette>colorAccent` →
        // immediate parent `vertical_global_palette` contains "palette"
        // → suggest sanitize_key of the parent.
        $this->assertSame('vertical_global_palette', $r->deriveSuggestedPaletteGroupKey([
            'field_type' => 'color_picker',
            'field_name_path' => 'brand_color_palette>vertical_global_palette>colorAccent',
        ]));
        // Case-insensitive match on the parent.
        $this->assertSame('mypalette', $r->deriveSuggestedPaletteGroupKey([
            'field_type' => 'color_picker',
            'field_name_path' => 'root>MyPalette>tone1',
        ]));
        // Parent with hyphens is fine — sanitize_key preserves them.
        $this->assertSame('brand-palette', $r->deriveSuggestedPaletteGroupKey([
            'field_type' => 'color_picker',
            'field_name_path' => 'root>brand-palette>swatch1',
        ]));
    }

    public function test_no_palette_group_key_suggestion_for_non_color_picker(): void
    {
        $r = $this->recommender();
        $this->assertSame('', $r->deriveSuggestedPaletteGroupKey([
            'field_type' => 'text',
            'field_name_path' => 'brand_color_palette>vertical_global_palette>caption',
        ]));
        $this->assertSame('', $r->deriveSuggestedPaletteGroupKey([
            'field_type' => 'image',
            'field_name_path' => 'brand_color_palette>vertical_global_palette>logo',
        ]));
    }

    public function test_no_palette_group_key_suggestion_when_parent_name_does_not_contain_palette(): void
    {
        $r = $this->recommender();
        $this->assertSame('', $r->deriveSuggestedPaletteGroupKey([
            'field_type' => 'color_picker',
            'field_name_path' => 'brand>colors>accent',
        ]));
        // Contains "palette" but only as substring of an unrelated word
        // like "palette" itself would still match — the heuristic is a
        // deliberately soft filter (curator can always override).
        // But a leaf-only path (no `>`) has no immediate parent → no suggestion.
        $this->assertSame('', $r->deriveSuggestedPaletteGroupKey([
            'field_type' => 'color_picker',
            'field_name_path' => 'accent',
        ]));
        $this->assertSame('', $r->deriveSuggestedPaletteGroupKey([
            'field_type' => 'color_picker',
            'field_name_path' => '',
        ]));
    }

    public function test_no_palette_group_key_suggestion_when_candidate_missing_field_type(): void
    {
        $r = $this->recommender();
        $this->assertSame('', $r->deriveSuggestedPaletteGroupKey([
            'field_name_path' => 'brand_color_palette>vertical_global_palette>colorAccent',
            // field_type missing entirely
        ]));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function cand(array $overrides)
    {
        return array_merge([
            'options_page' => 'site-settings',
            'field_type' => 'text',
            'field_name_path' => '',
            'field_label' => '',
            'is_repeater_family' => false,
        ], $overrides);
    }
}
