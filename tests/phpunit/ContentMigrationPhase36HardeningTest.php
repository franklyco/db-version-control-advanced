<?php

class ContentMigrationPhase36HardeningTest extends WP_UnitTestCase
{
    public function test_element_extractor_marks_partial_on_max_element_cap(): void
    {
        $html = '<html><body><main><h1>Home</h1>';
        for ($i = 1; $i <= 180; $i++) {
            $html .= '<p>Paragraph ' . $i . '</p>';
        }
        $html .= '</main></body></html>';

        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        $xpath = new DOMXPath($doc);

        $options = DBVC_CC_Contracts::get_settings_defaults();
        $options['capture_max_elements_per_page'] = 100;

        $artifacts = DBVC_CC_Element_Extractor_Service::extract_artifacts($xpath, [$doc], 'https://phase36-hardening.test/', $options);
        $elements_payload = isset($artifacts['elements']) && is_array($artifacts['elements']) ? $artifacts['elements'] : [];
        $processing = isset($elements_payload['processing']) && is_array($elements_payload['processing']) ? $elements_payload['processing'] : [];

        $this->assertNotEmpty($elements_payload);
        $this->assertTrue(!empty($elements_payload['truncated']));
        $this->assertTrue(!empty($processing['is_partial']));
        $this->assertSame('max_elements', $processing['partial_reason']);
        $this->assertIsArray($processing['resume_marker']);
        $this->assertArrayHasKey('context_node_index', $processing['resume_marker']);
        $this->assertArrayHasKey('node_offset', $processing['resume_marker']);
    }

    public function test_section_segmenter_marks_partial_on_stage_timeout(): void
    {
        $elements = [];
        for ($i = 1; $i <= 30; $i++) {
            $elements[] = [
                'element_id' => 'dbvc_cc_el_' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'tag' => ($i === 1) ? 'h1' : 'p',
                'text' => 'Element ' . $i,
                'sequence_index' => $i,
            ];
        }

        $sections_payload = DBVC_CC_Section_Segmenter_Service::build_artifact(
            [
                'elements' => $elements,
            ],
            'https://phase36-hardening.test/',
            [
                'capture_stage_timeout_seconds' => 0,
                'capture_processing_chunk_size' => 10,
            ]
        );

        $processing = isset($sections_payload['processing']) && is_array($sections_payload['processing']) ? $sections_payload['processing'] : [];
        $this->assertTrue(!empty($processing['is_partial']));
        $this->assertSame('timeout', $processing['partial_reason']);
        $this->assertIsArray($processing['resume_marker']);
        $this->assertSame(0, (int) ($processing['resume_marker']['element_offset'] ?? -1));
    }

    public function test_context_bundle_includes_pii_hint_tags_and_privacy_metadata(): void
    {
        $scraped_data = [
            'slug' => 'home',
            'page_name' => 'Home',
            'meta' => [
                'description' => 'Fixture description',
            ],
            'content' => [
                'headings' => ['Home'],
            ],
            'compliance' => [
                'pii_flags' => [
                    'emails_count' => 2,
                    'phones_count' => 1,
                    'forms_count' => 1,
                ],
                'redaction_rules' => [
                    'emails' => true,
                    'phones' => true,
                    'forms' => true,
                ],
                'requires_legal_review' => true,
            ],
        ];

        $elements_payload = [
            'elements' => [
                [
                    'element_id' => 'dbvc_cc_el_0001',
                    'text' => 'Contact us',
                    'link_target' => 'https://phase36-hardening.test/contact',
                ],
            ],
        ];
        $sections_payload = [
            'sections' => [
                [
                    'section_id' => 'dbvc_cc_sec_0001',
                    'section_label_candidate' => 'Contact',
                    'signals' => ['heading_level' => 2],
                    'element_ids' => ['dbvc_cc_el_0001'],
                ],
            ],
        ];
        $scrub_report_payload = [
            'profile' => DBVC_CC_Contracts::SCRUB_PROFILE_DETERMINISTIC_DEFAULT,
            'totals' => ['kept' => 0, 'dropped' => 0, 'hashed' => 0, 'tokenized' => 0],
            'by_attribute' => [],
        ];

        $bundle = DBVC_CC_Context_Bundle_Service::build_artifact(
            $scraped_data,
            $elements_payload,
            $sections_payload,
            $scrub_report_payload,
            'https://phase36-hardening.test/'
        );

        $this->assertIsArray($bundle);
        $this->assertSame(['email', 'phone', 'form'], $bundle['entity_hints']['pii_hint_tags']);
        $this->assertSame(true, $bundle['privacy']['redaction_rules']['emails']);
        $this->assertSame(true, $bundle['privacy']['redaction_rules']['phones']);
        $this->assertSame(true, $bundle['privacy']['redaction_rules']['forms']);
        $this->assertTrue(!empty($bundle['privacy']['requires_legal_review']));
    }
}
