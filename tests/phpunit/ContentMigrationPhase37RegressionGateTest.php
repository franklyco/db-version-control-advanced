<?php

class ContentMigrationPhase37RegressionGateTest extends WP_UnitTestCase
{
    private $domain_key = 'phase37-regression.test';
    private $relative_path = 'home';
    private $domain_dir = '';
    private $original_settings = [];

    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        DBVC_CC_Contracts::ensure_phase_zero_defaults();
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_WORKBENCH, '1');
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_CATALOG_BRIDGE, '1');
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MEDIA_MAPPING_BRIDGE, '1');

        $this->original_settings = DBVC_CC_Settings_Service::get_options();
        $settings = $this->original_settings;
        $settings['storage_path'] = DBVC_CC_Contracts::STORAGE_DEFAULT_PATH;
        $settings['dbvc_cc_media_block_private_hosts'] = 1;
        $settings['dbvc_cc_media_preview_thumbnail_enabled'] = 1;
        update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $settings);

        register_post_meta('page', 'hero_subheading', ['type' => 'string', 'single' => true, 'show_in_rest' => true]);
        register_post_meta('page', 'faq_question', ['type' => 'string', 'single' => false, 'show_in_rest' => true]);
        register_post_meta('page', 'faq_answer', ['type' => 'string', 'single' => false, 'show_in_rest' => true]);
        register_post_meta('page', 'cta_button_url', ['type' => 'string', 'single' => true, 'show_in_rest' => true]);

        $this->build_fixture_artifacts();
    }

    public function tear_down(): void
    {
        if (is_string($this->domain_dir) && $this->domain_dir !== '') {
            $this->remove_directory($this->domain_dir);
        }

        if (! empty($this->original_settings)) {
            update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $this->original_settings);
        }

        parent::tear_down();
    }

    public function test_phase37_reruns_are_deterministic_for_catalog_and_candidates(): void
    {
        $catalog_one = DBVC_CC_Target_Field_Catalog_Service::get_instance()->build_catalog($this->domain_key, true);
        $catalog_two = DBVC_CC_Target_Field_Catalog_Service::get_instance()->build_catalog($this->domain_key, true);

        $this->assertIsArray($catalog_one);
        $this->assertIsArray($catalog_two);
        $this->assertSame((string) $catalog_one['catalog_fingerprint'], (string) $catalog_two['catalog_fingerprint']);

        $sections_one = DBVC_CC_Section_Field_Candidate_Service::get_instance()->build_candidates($this->domain_key, $this->relative_path, true);
        $sections_two = DBVC_CC_Section_Field_Candidate_Service::get_instance()->build_candidates($this->domain_key, $this->relative_path, true);

        $this->assertIsArray($sections_one);
        $this->assertIsArray($sections_two);
        $this->assertSame(
            wp_json_encode($this->snapshot_section_candidates_contract($sections_one['section_candidates'])),
            wp_json_encode($this->snapshot_section_candidates_contract($sections_two['section_candidates']))
        );

        $media_one = DBVC_CC_Media_Candidate_Service::get_instance()->build_candidates($this->domain_key, $this->relative_path, true);
        $media_two = DBVC_CC_Media_Candidate_Service::get_instance()->build_candidates($this->domain_key, $this->relative_path, true);

        $this->assertIsArray($media_one);
        $this->assertIsArray($media_two);
        $this->assertSame(
            wp_json_encode($this->snapshot_media_candidates_contract($media_one['media_candidates'])),
            wp_json_encode($this->snapshot_media_candidates_contract($media_two['media_candidates']))
        );
    }

    public function test_phase37_fixture_locked_transport_snapshots(): void
    {
        $catalog_result = DBVC_CC_Target_Field_Catalog_Service::get_instance()->build_catalog($this->domain_key, true);
        $section_result = DBVC_CC_Section_Field_Candidate_Service::get_instance()->build_candidates($this->domain_key, $this->relative_path, true);
        $media_result = DBVC_CC_Media_Candidate_Service::get_instance()->build_candidates($this->domain_key, $this->relative_path, true);

        $mapping_save = DBVC_CC_Mapping_Decision_Service::get_instance()->save_decision(
            $this->domain_key,
            $this->relative_path,
            [
                'decision_status' => 'pending',
                'approved_mappings' => [
                    ['section_id' => 'dbvc_cc_sec_hero', 'target_ref' => 'core:post_title'],
                    ['section_id' => 'dbvc_cc_sec_faq', 'target_ref' => 'meta:post:page:faq_question'],
                ],
                'overrides' => [
                    ['section_id' => 'dbvc_cc_sec_faq', 'target_ref' => 'meta:post:page:faq_question', 'override_target_ref' => 'meta:post:page:faq_answer'],
                ],
                'rejections' => [],
                'unresolved_fields' => [],
            ],
            get_current_user_id()
        );

        $media_save = DBVC_CC_Media_Decision_Service::get_instance()->save_decision(
            $this->domain_key,
            $this->relative_path,
            [
                'decision_status' => 'pending',
                'approved' => [
                    ['media_id' => 'dbvc_cc_med_manual_hero', 'target_ref' => 'core:featured_image', 'source_url' => 'https://phase37-regression.test/wp-content/uploads/2026/03/hero.jpg'],
                    ['media_id' => 'dbvc_cc_med_manual_pdf', 'target_ref' => 'meta:post:page:download_asset', 'source_url' => 'https://phase37-regression.test/files/brochure.pdf'],
                ],
                'overrides' => [
                    ['media_id' => 'dbvc_cc_med_manual_pdf', 'target_ref' => 'meta:post:page:download_asset', 'override_target_ref' => 'core:post_content'],
                ],
                'ignored' => [
                    ['media_id' => 'dbvc_cc_med_manual_logo', 'reason' => 'ignored_by_reviewer'],
                ],
                'conflicts' => [],
            ],
            get_current_user_id()
        );

        $this->assertIsArray($catalog_result);
        $this->assertIsArray($section_result);
        $this->assertIsArray($media_result);
        $this->assertIsArray($mapping_save);
        $this->assertIsArray($media_save);

        $this->assertFixtureMatches(
            'catalog-contract.expected.json',
            $this->snapshot_catalog_contract($catalog_result['catalog'])
        );
        $this->assertFixtureMatches(
            'section-candidates-contract.expected.json',
            $this->snapshot_section_candidates_contract($section_result['section_candidates'])
        );
        $this->assertFixtureMatches(
            'media-candidates-contract.expected.json',
            $this->snapshot_media_candidates_contract($media_result['media_candidates'])
        );
        $this->assertFixtureMatches(
            'mapping-decision-contract.expected.json',
            $this->snapshot_mapping_decision_contract($mapping_save['mapping_decision'])
        );
        $this->assertFixtureMatches(
            'media-decision-contract.expected.json',
            $this->snapshot_media_decision_contract($media_save['media_decision'])
        );
    }

    /**
     * @param array<string, mixed> $catalog
     * @return array<string, mixed>
     */
    private function snapshot_catalog_contract(array $catalog): array
    {
        $meta_catalog = isset($catalog['meta_catalog']) && is_array($catalog['meta_catalog']) ? $catalog['meta_catalog'] : [];
        $page_meta = isset($meta_catalog['post']['page']) && is_array($meta_catalog['post']['page']) ? $meta_catalog['post']['page'] : [];

        return [
            'artifact_type' => isset($catalog['artifact_type']) ? (string) $catalog['artifact_type'] : '',
            'domain' => isset($catalog['domain']) ? (string) $catalog['domain'] : '',
            'artifact_schema_version' => isset($catalog['artifact_schema_version']) ? (string) $catalog['artifact_schema_version'] : '',
            'catalog_fingerprint_present' => ! empty($catalog['catalog_fingerprint']),
            'has_cpt_page' => isset($catalog['cpt_catalog']['page']),
            'has_taxonomy_category' => isset($catalog['taxonomy_catalog']['category']),
            'has_term_catalog_category' => isset($catalog['term_catalog']['category']),
            'meta_checks' => [
                'hero_subheading' => isset($page_meta['hero_subheading']),
                'faq_question' => isset($page_meta['faq_question']),
                'faq_answer' => isset($page_meta['faq_answer']),
                'cta_button_url' => isset($page_meta['cta_button_url']),
            ],
            'has_media_field_catalog' => ! empty($catalog['media_field_catalog']) && is_array($catalog['media_field_catalog']),
        ];
    }

    /**
     * @param mixed $payload
     * @return array<string, mixed>
     */
    private function snapshot_section_candidates_contract($payload): array
    {
        $data = is_array($payload) ? $payload : [];
        $sections = isset($data['sections']) && is_array($data['sections']) ? $data['sections'] : [];
        $section_snapshots = [];
        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $candidate_rows = isset($section['deterministic_candidates']) && is_array($section['deterministic_candidates']) ? $section['deterministic_candidates'] : [];
            $target_refs = [];
            foreach ($candidate_rows as $candidate_row) {
                if (! is_array($candidate_row) || empty($candidate_row['target_ref'])) {
                    continue;
                }
                $target_refs[] = (string) $candidate_row['target_ref'];
            }
            $target_refs = array_values(array_unique($target_refs));
            sort($target_refs);

            $section_snapshots[] = [
                'section_id' => isset($section['section_id']) ? (string) $section['section_id'] : '',
                'section_archetype' => isset($section['section_archetype']) ? (string) $section['section_archetype'] : '',
                'has_core_post_title' => in_array('core:post_title', $target_refs, true),
                'has_core_post_content' => in_array('core:post_content', $target_refs, true),
                'has_faq_question_meta' => in_array('meta:post:page:faq_question', $target_refs, true),
                'has_faq_answer_meta' => in_array('meta:post:page:faq_answer', $target_refs, true),
                'unresolved_count' => isset($section['unresolved_fields']) && is_array($section['unresolved_fields']) ? count($section['unresolved_fields']) : 0,
            ];
        }

        usort($section_snapshots, static function (array $left, array $right): int {
            return strnatcasecmp((string) $left['section_id'], (string) $right['section_id']);
        });

        return [
            'artifact_type' => isset($data['artifact_type']) ? (string) $data['artifact_type'] : '',
            'source_url' => isset($data['source_url']) ? (string) $data['source_url'] : '',
            'catalog_fingerprint_present' => ! empty($data['catalog_fingerprint']),
            'section_count' => count($section_snapshots),
            'sections' => $section_snapshots,
        ];
    }

    /**
     * @param mixed $payload
     * @return array<string, mixed>
     */
    private function snapshot_media_candidates_contract($payload): array
    {
        $data = is_array($payload) ? $payload : [];
        $policy = isset($data['policy']) && is_array($data['policy']) ? $data['policy'] : [];
        $stats = isset($data['stats']) && is_array($data['stats']) ? $data['stats'] : [];
        $media_items = isset($data['media_items']) && is_array($data['media_items']) ? $data['media_items'] : [];
        $item_snapshots = [];

        foreach ($media_items as $media_item) {
            if (! is_array($media_item)) {
                continue;
            }

            $policy_trace = isset($media_item['policy_trace']) && is_array($media_item['policy_trace']) ? $media_item['policy_trace'] : [];
            $item_snapshots[] = [
                'normalized_url' => isset($media_item['normalized_url']) ? (string) $media_item['normalized_url'] : '',
                'media_kind' => isset($media_item['media_kind']) ? (string) $media_item['media_kind'] : '',
                'preview_status' => isset($media_item['preview_status']) ? (string) $media_item['preview_status'] : '',
                'has_preview_ref' => ! empty($media_item['preview_ref']),
                'domain_policy' => isset($policy_trace['dbvc_cc_domain_policy']) ? (string) $policy_trace['dbvc_cc_domain_policy'] : '',
                'mime_allowed' => ! empty($policy_trace['dbvc_cc_mime_allowed']),
            ];
        }

        usort($item_snapshots, static function (array $left, array $right): int {
            return strnatcasecmp((string) $left['normalized_url'], (string) $right['normalized_url']);
        });

        $blocked_reasons = [];
        $blocked_examples = isset($stats['blocked_url_examples']) && is_array($stats['blocked_url_examples']) ? $stats['blocked_url_examples'] : [];
        foreach ($blocked_examples as $example) {
            if (! is_array($example) || empty($example['reason'])) {
                continue;
            }
            $blocked_reasons[] = (string) $example['reason'];
        }
        $blocked_reasons = array_values(array_unique($blocked_reasons));
        sort($blocked_reasons);

        return [
            'artifact_type' => isset($data['artifact_type']) ? (string) $data['artifact_type'] : '',
            'source_url' => isset($data['source_url']) ? (string) $data['source_url'] : '',
            'catalog_fingerprint_present' => ! empty($data['catalog_fingerprint']),
            'policy' => [
                'download_policy' => isset($policy['dbvc_cc_media_download_policy']) ? (string) $policy['dbvc_cc_media_download_policy'] : '',
                'discovery_mode' => isset($policy['dbvc_cc_media_discovery_mode']) ? (string) $policy['dbvc_cc_media_discovery_mode'] : '',
                'preview_enabled' => isset($policy['dbvc_cc_media_preview_thumbnail_enabled']) ? (int) $policy['dbvc_cc_media_preview_thumbnail_enabled'] : 0,
                'block_private_hosts' => isset($policy['dbvc_cc_media_block_private_hosts']) ? (int) $policy['dbvc_cc_media_block_private_hosts'] : 0,
            ],
            'stats' => [
                'total_candidates' => isset($stats['total_candidates']) ? (int) $stats['total_candidates'] : 0,
                'with_preview_refs' => isset($stats['with_preview_refs']) ? (int) $stats['with_preview_refs'] : 0,
                'blocked_url_count' => isset($stats['blocked_url_count']) ? (int) $stats['blocked_url_count'] : 0,
                'suppressed_preview_count' => isset($stats['suppressed_preview_count']) ? (int) $stats['suppressed_preview_count'] : 0,
                'mime_blocked_count' => isset($stats['mime_blocked_count']) ? (int) $stats['mime_blocked_count'] : 0,
                'blocked_url_reasons' => $blocked_reasons,
            ],
            'items' => $item_snapshots,
        ];
    }

    /**
     * @param mixed $payload
     * @return array<string, mixed>
     */
    private function snapshot_mapping_decision_contract($payload): array
    {
        $data = is_array($payload) ? $payload : [];
        $provenance = isset($data['provenance']) && is_array($data['provenance']) ? $data['provenance'] : [];

        return [
            'artifact_type' => isset($data['artifact_type']) ? (string) $data['artifact_type'] : '',
            'decision_status' => isset($data['decision_status']) ? (string) $data['decision_status'] : '',
            'approved_mappings' => $this->snapshot_decision_rows(isset($data['approved_mappings']) ? $data['approved_mappings'] : []),
            'overrides' => $this->snapshot_decision_rows(isset($data['overrides']) ? $data['overrides'] : []),
            'updated_by_positive' => ! empty($data['updated_by']),
            'updated_at_present' => ! empty($data['updated_at']),
            'provenance' => [
                'source_url_present' => ! empty($provenance['source_url']),
                'decision_actor_matches_updated_by' => isset($data['updated_by'], $provenance['decision_actor']) && (int) $data['updated_by'] === (int) $provenance['decision_actor'],
                'decision_timestamp_present' => ! empty($provenance['decision_timestamp']),
                'decision_policy_mode' => isset($provenance['decision_policy_mode']) ? (string) $provenance['decision_policy_mode'] : '',
            ],
        ];
    }

    /**
     * @param mixed $payload
     * @return array<string, mixed>
     */
    private function snapshot_media_decision_contract($payload): array
    {
        $data = is_array($payload) ? $payload : [];
        $provenance = isset($data['provenance']) && is_array($data['provenance']) ? $data['provenance'] : [];

        return [
            'artifact_type' => isset($data['artifact_type']) ? (string) $data['artifact_type'] : '',
            'decision_status' => isset($data['decision_status']) ? (string) $data['decision_status'] : '',
            'approved' => $this->snapshot_decision_rows(isset($data['approved']) ? $data['approved'] : []),
            'overrides' => $this->snapshot_decision_rows(isset($data['overrides']) ? $data['overrides'] : []),
            'ignored' => $this->snapshot_decision_rows(isset($data['ignored']) ? $data['ignored'] : []),
            'updated_by_positive' => ! empty($data['updated_by']),
            'updated_at_present' => ! empty($data['updated_at']),
            'provenance' => [
                'source_url_present' => ! empty($provenance['source_url']),
                'decision_actor_matches_updated_by' => isset($data['updated_by'], $provenance['decision_actor']) && (int) $data['updated_by'] === (int) $provenance['decision_actor'],
                'decision_timestamp_present' => ! empty($provenance['decision_timestamp']),
                'decision_policy_mode' => isset($provenance['decision_policy_mode']) ? (string) $provenance['decision_policy_mode'] : '',
            ],
        ];
    }

    /**
     * @param mixed $rows
     * @return array<int, array<string, string>>
     */
    private function snapshot_decision_rows($rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $snapshots = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $snapshot = [];
            foreach (['section_id', 'media_id', 'target_ref', 'override_target_ref', 'reason'] as $key) {
                if (isset($row[$key])) {
                    $snapshot[$key] = (string) $row[$key];
                }
            }
            if (! empty($snapshot)) {
                $snapshots[] = $snapshot;
            }
        }

        usort($snapshots, static function (array $left, array $right): int {
            return strnatcasecmp(wp_json_encode($left), wp_json_encode($right));
        });

        return $snapshots;
    }

    /**
     * @return array<string, mixed>
     */
    private function load_fixture(string $fixture_name): array
    {
        $fixture_path = dirname(__DIR__, 2) . '/addons/content-migration/tests/fixtures/mapping/' . $fixture_name;
        $raw = file_get_contents($fixture_path);
        $this->assertIsString($raw, 'Fixture file could not be read: ' . $fixture_name);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'Fixture file is not valid JSON object: ' . $fixture_name);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $actual
     */
    private function assertFixtureMatches(string $fixture_name, array $actual): void
    {
        $expected = $this->load_fixture($fixture_name);
        $this->assertSame(wp_json_encode($expected), wp_json_encode($actual), 'Fixture mismatch: ' . $fixture_name);
    }

    private function build_fixture_artifacts(): void
    {
        $upload_dir = wp_upload_dir();
        $base_dir = isset($upload_dir['basedir']) ? (string) $upload_dir['basedir'] : '';
        $storage_dir = trailingslashit($base_dir) . 'contentcollector';
        $this->domain_dir = trailingslashit($storage_dir) . $this->domain_key;
        $page_dir = trailingslashit($this->domain_dir) . $this->relative_path;

        wp_mkdir_p($page_dir);

        $slug = 'home';
        $source_url = 'https://' . $this->domain_key . '/';

        $main_artifact = [
            'page_name' => 'Home',
            'slug' => $slug,
            'source_url' => $source_url,
            'provenance' => [
                'source_url' => $source_url,
                'canonical_source_url' => $source_url,
                'content_hash' => hash('sha256', 'phase37-regression-home'),
            ],
            'content' => [
                'images' => [
                    '/wp-content/uploads/2026/03/hero.jpg',
                    [
                        'url' => 'https://cdn.phase37.example/logo.svg',
                        'alt' => 'Brand Logo',
                    ],
                ],
                'sections' => [
                    [
                        'id' => 'dbvc_cc_sec_hero',
                        'heading' => 'Hero',
                        'images' => [
                            ['url' => 'https://phase37-regression.test/wp-content/uploads/2026/03/gallery-1.jpg'],
                            ['url' => 'https://phase37-regression.test/wp-content/uploads/2026/03/gallery-2.jpg'],
                        ],
                    ],
                ],
            ],
        ];

        $elements_v2 = [
            'artifact_schema_version' => '2.0.0',
            'artifact_type' => 'elements.v2',
            'source_url' => $source_url,
            'capture_mode' => 'deep',
            'element_count' => 7,
            'truncated' => false,
            'processing' => [
                'is_partial' => false,
                'partial_reason' => '',
                'resume_marker' => new stdClass(),
            ],
            'elements' => [
                [
                    'element_id' => 'dbvc_cc_el_hero_h1',
                    'tag' => 'h1',
                    'text' => 'Managed IT Services',
                    'sequence_index' => 1,
                    'attributes' => [],
                ],
                [
                    'element_id' => 'dbvc_cc_el_hero_img',
                    'tag' => 'img',
                    'text' => 'Hero image',
                    'sequence_index' => 2,
                    'attributes' => [
                        'src' => '/wp-content/uploads/2026/03/hero.jpg',
                        'alt' => 'Hero photo',
                    ],
                ],
                [
                    'element_id' => 'dbvc_cc_el_faq_h2',
                    'tag' => 'h2',
                    'text' => 'FAQs',
                    'sequence_index' => 3,
                    'attributes' => [],
                ],
                [
                    'element_id' => 'dbvc_cc_el_faq_p',
                    'tag' => 'p',
                    'text' => 'How quickly can onboarding begin?',
                    'sequence_index' => 4,
                    'attributes' => [],
                ],
                [
                    'element_id' => 'dbvc_cc_el_0005',
                    'tag' => 'a',
                    'text' => 'Download brochure',
                    'sequence_index' => 5,
                    'link_target' => 'https://phase37-regression.test/files/brochure.pdf',
                ],
                [
                    'element_id' => 'dbvc_cc_el_0006',
                    'tag' => 'iframe',
                    'text' => 'Watch video',
                    'sequence_index' => 6,
                    'attributes' => [
                        'src' => 'https://www.youtube.com/watch?v=abc123xyz',
                    ],
                ],
                [
                    'element_id' => 'dbvc_cc_el_0007',
                    'tag' => 'img',
                    'text' => 'Blocked private host image',
                    'sequence_index' => 7,
                    'attributes' => [
                        'src' => 'http://127.0.0.1/private-network.png',
                    ],
                ],
            ],
        ];

        $sections_v2 = [
            'artifact_schema_version' => '2.0.0',
            'artifact_type' => 'sections.v2',
            'source_url' => $source_url,
            'section_count' => 2,
            'processing' => [
                'is_partial' => false,
                'partial_reason' => '',
                'resume_marker' => new stdClass(),
            ],
            'sections' => [
                [
                    'section_id' => 'dbvc_cc_sec_hero',
                    'order' => 1,
                    'section_label_candidate' => 'Hero',
                    'start_sequence_index' => 1,
                    'end_sequence_index' => 4,
                    'element_ids' => ['dbvc_cc_el_hero_h1', 'dbvc_cc_el_hero_img', 'dbvc_cc_el_faq_h2', 'dbvc_cc_el_faq_p'],
                ],
                [
                    'section_id' => 'dbvc_cc_sec_faq',
                    'order' => 2,
                    'section_label_candidate' => 'FAQ',
                    'start_sequence_index' => 5,
                    'end_sequence_index' => 7,
                    'element_ids' => ['dbvc_cc_el_0005', 'dbvc_cc_el_0006', 'dbvc_cc_el_0007'],
                ],
            ],
        ];

        $section_typing_v2 = [
            'artifact_schema_version' => '2.0.0',
            'artifact_type' => 'section-typing.v2',
            'source_url' => $source_url,
            'section_typings' => [
                [
                    'section_id' => 'dbvc_cc_sec_hero',
                    'section_type_candidate' => 'hero',
                    'mode' => 'deterministic',
                ],
                [
                    'section_id' => 'dbvc_cc_sec_faq',
                    'section_type_candidate' => 'faq',
                    'mode' => 'deterministic',
                ],
            ],
        ];

        $this->write_json($page_dir . '/' . $slug . '.json', $main_artifact);
        $this->write_json($page_dir . '/' . $slug . '.elements.v2.json', $elements_v2);
        $this->write_json($page_dir . '/' . $slug . '.sections.v2.json', $sections_v2);
        $this->write_json($page_dir . '/' . $slug . '.section-typing.v2.json', $section_typing_v2);
    }

    private function write_json(string $file, array $payload): void
    {
        file_put_contents($file, wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function remove_directory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if (! is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = trailingslashit($directory) . $entry;
            if (is_dir($path)) {
                $this->remove_directory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
