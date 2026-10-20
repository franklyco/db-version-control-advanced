<?php

class ContentMigrationPhase36DeterminismTest extends WP_UnitTestCase
{
    public function test_element_extraction_is_deterministic_for_identical_html(): void
    {
        $options = DBVC_CC_Contracts::get_settings_defaults();
        $options['capture_max_elements_per_page'] = 500;
        $options['capture_max_chars_per_element'] = 1000;
        $options['capture_stage_timeout_seconds'] = 30;

        $run_one = $this->extract_elements_for_html($this->get_fixture_html(), $options);
        $run_two = $this->extract_elements_for_html($this->get_fixture_html(), $options);

        $elements_one = isset($run_one['elements']['elements']) && is_array($run_one['elements']['elements']) ? $run_one['elements']['elements'] : [];
        $elements_two = isset($run_two['elements']['elements']) && is_array($run_two['elements']['elements']) ? $run_two['elements']['elements'] : [];

        $this->assertNotEmpty($elements_one);
        $this->assertSame(count($elements_one), count($elements_two));
        $this->assertSame(
            array_values(array_map('strval', array_column($elements_one, 'element_id'))),
            array_values(array_map('strval', array_column($elements_two, 'element_id')))
        );
        $this->assertSame(
            array_values(array_map('intval', array_column($elements_one, 'sequence_index'))),
            array_values(array_map('intval', array_column($elements_two, 'sequence_index')))
        );

        $processing_one = isset($run_one['elements']['processing']) && is_array($run_one['elements']['processing']) ? $run_one['elements']['processing'] : [];
        $processing_two = isset($run_two['elements']['processing']) && is_array($run_two['elements']['processing']) ? $run_two['elements']['processing'] : [];
        $this->assertFalse(!empty($processing_one['is_partial']));
        $this->assertFalse(!empty($processing_two['is_partial']));
    }

    public function test_section_segmentation_is_deterministic_for_identical_elements(): void
    {
        $options = DBVC_CC_Contracts::get_settings_defaults();
        $options['capture_stage_timeout_seconds'] = 30;
        $options['capture_processing_chunk_size'] = 100;

        $extracted = $this->extract_elements_for_html($this->get_fixture_html(), $options);
        $elements_payload = isset($extracted['elements']) && is_array($extracted['elements']) ? $extracted['elements'] : [];

        $run_one = DBVC_CC_Section_Segmenter_Service::build_artifact($elements_payload, 'https://phase36-determinism.test/', $options);
        $run_two = DBVC_CC_Section_Segmenter_Service::build_artifact($elements_payload, 'https://phase36-determinism.test/', $options);

        $sections_one = isset($run_one['sections']) && is_array($run_one['sections']) ? $run_one['sections'] : [];
        $sections_two = isset($run_two['sections']) && is_array($run_two['sections']) ? $run_two['sections'] : [];

        $this->assertNotEmpty($sections_one);
        $this->assertSame($run_one['section_count'], $run_two['section_count']);
        $this->assertSame(wp_json_encode($sections_one), wp_json_encode($sections_two));
    }

    public function test_section_typing_stays_in_fallback_mode_with_consistent_outputs(): void
    {
        $options = DBVC_CC_Contracts::get_settings_defaults();
        $options['ai_enable_section_typing'] = 0;
        $options['ai_section_typing_confidence_threshold'] = 0.65;

        $extracted = $this->extract_elements_for_html($this->get_fixture_html(), $options);
        $elements_payload = isset($extracted['elements']) && is_array($extracted['elements']) ? $extracted['elements'] : [];
        $sections_payload = DBVC_CC_Section_Segmenter_Service::build_artifact($elements_payload, 'https://phase36-determinism.test/', $options);

        $fallback_run = DBVC_CC_Section_Typing_Service::build_artifact(
            $sections_payload,
            $elements_payload,
            $options,
            'https://phase36-determinism.test/'
        );

        $options['ai_enable_section_typing'] = 1;
        $ai_flag_run = DBVC_CC_Section_Typing_Service::build_artifact(
            $sections_payload,
            $elements_payload,
            $options,
            'https://phase36-determinism.test/'
        );

        $this->assertSame('fallback', $fallback_run['mode']);
        $this->assertSame('fallback', $ai_flag_run['mode']);
        $this->assertSame(
            wp_json_encode($fallback_run['section_typings']),
            wp_json_encode($ai_flag_run['section_typings'])
        );
    }

    /**
     * @param string $html
     * @param array<string, mixed> $options
     * @return array<string, array<string, mixed>>
     */
    private function extract_elements_for_html(string $html, array $options): array
    {
        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        $xpath = new DOMXPath($doc);

        return DBVC_CC_Element_Extractor_Service::extract_artifacts(
            $xpath,
            [$doc],
            'https://phase36-determinism.test/',
            $options
        );
    }

    private function get_fixture_html(): string
    {
        return '<html><body><main>'
            . '<h1>Determinism Fixture</h1>'
            . '<p class="intro-copy">Intro copy text for fixture.</p>'
            . '<h2 id="features">Features</h2>'
            . '<ul><li>Feature One</li><li>Feature Two</li></ul>'
            . '<h2>Contact</h2>'
            . '<p>Contact us today for a quote.</p>'
            . '<a href="/contact" class="cta-link">Get Started</a>'
            . '</main></body></html>';
    }
}
