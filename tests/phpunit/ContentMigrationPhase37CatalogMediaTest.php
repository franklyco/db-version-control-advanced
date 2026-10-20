<?php

class ContentMigrationPhase37CatalogMediaTest extends WP_UnitTestCase
{
    private $domain_key = 'phase37-example.test';
    private $relative_path = 'home';
    private $domain_dir = '';
    private $original_settings = [];

    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        if (class_exists('DBVC_CC_Contracts')) {
            DBVC_CC_Contracts::ensure_phase_zero_defaults();
            update_option(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_CATALOG_BRIDGE, '1');
            update_option(DBVC_CC_Contracts::OPTION_FLAG_MEDIA_MAPPING_BRIDGE, '1');
        }

        $this->original_settings = class_exists('DBVC_CC_Settings_Service')
            ? DBVC_CC_Settings_Service::get_options()
            : [];

        if (! empty($this->original_settings) && class_exists('DBVC_CC_Contracts')) {
            $settings = $this->original_settings;
            $settings['storage_path'] = DBVC_CC_Contracts::STORAGE_DEFAULT_PATH;
            update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $settings);
        }

        $this->build_fixture_artifacts();
    }

    public function tear_down(): void
    {
        if (is_string($this->domain_dir) && $this->domain_dir !== '') {
            $this->remove_directory($this->domain_dir);
        }

        if (! empty($this->original_settings) && class_exists('DBVC_CC_Contracts')) {
            update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $this->original_settings);
        }

        parent::tear_down();
    }

    public function test_target_field_catalog_build_and_reuse(): void
    {
        $result = DBVC_CC_Target_Field_Catalog_Service::get_instance()->build_catalog($this->domain_key, true);
        $this->assertIsArray($result);
        $this->assertSame('built', $result['status']);
        $this->assertArrayHasKey('catalog', $result);

        $catalog = is_array($result['catalog']) ? $result['catalog'] : [];
        $this->assertSame('target-field-catalog.v1', $catalog['artifact_type']);
        $this->assertSame($this->domain_key, $catalog['domain']);
        $this->assertArrayHasKey('cpt_catalog', $catalog);
        $this->assertArrayHasKey('taxonomy_catalog', $catalog);
        $this->assertArrayHasKey('term_catalog', $catalog);
        $this->assertArrayHasKey('meta_catalog', $catalog);
        $this->assertArrayHasKey('acf_catalog', $catalog);
        $this->assertArrayHasKey('media_field_catalog', $catalog);
        $this->assertNotSame('', (string) $catalog['catalog_fingerprint']);

        $reused = DBVC_CC_Target_Field_Catalog_Service::get_instance()->build_catalog($this->domain_key, false);
        $this->assertIsArray($reused);
        $this->assertSame('reused', $reused['status']);
        $this->assertSame($result['catalog_fingerprint'], $reused['catalog_fingerprint']);
    }

    public function test_media_candidates_build_from_phase36_artifacts(): void
    {
        DBVC_CC_Target_Field_Catalog_Service::get_instance()->build_catalog($this->domain_key, true);
        $result = DBVC_CC_Media_Candidate_Service::get_instance()->build_candidates($this->domain_key, $this->relative_path, true);

        $this->assertIsArray($result);
        $this->assertSame('built', $result['status']);
        $this->assertArrayHasKey('media_candidates', $result);

        $payload = is_array($result['media_candidates']) ? $result['media_candidates'] : [];
        $this->assertSame('media-candidates.v1', $payload['artifact_type']);
        $this->assertArrayHasKey('media_items', $payload);
        $this->assertArrayHasKey('stats', $payload);
        $this->assertArrayHasKey('policy', $payload);
        $this->assertArrayHasKey('provenance', $payload);

        $media_items = isset($payload['media_items']) && is_array($payload['media_items']) ? $payload['media_items'] : [];
        $this->assertGreaterThanOrEqual(3, count($media_items));

        $media_kinds = [];
        $has_image_preview = false;
        $has_role_candidates = false;
        foreach ($media_items as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (isset($item['media_kind'])) {
                $media_kinds[] = (string) $item['media_kind'];
            }
            if (! empty($item['preview_ref'])) {
                $has_image_preview = true;
            }
            if (! empty($item['role_candidates']) && is_array($item['role_candidates'])) {
                $has_role_candidates = true;
            }
            $this->assertArrayHasKey('preview_status', $item);
            $this->assertArrayHasKey('policy_trace', $item);
            $this->assertArrayHasKey('provenance', $item);
        }

        $this->assertContains('image', $media_kinds);
        $this->assertContains('file', $media_kinds);
        $this->assertContains('embed', $media_kinds);
        $this->assertTrue($has_image_preview);
        $this->assertTrue($has_role_candidates);
        $this->assertSame(1, (int) ($payload['policy']['dbvc_cc_media_block_private_hosts'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($payload['stats']['blocked_url_count'] ?? 0));

        $loaded = DBVC_CC_Media_Candidate_Service::get_instance()->get_candidates($this->domain_key, $this->relative_path, false);
        $this->assertIsArray($loaded);
        $this->assertSame('loaded', $loaded['status']);
    }

    public function test_media_candidates_disable_image_previews_when_policy_disabled(): void
    {
        DBVC_CC_Target_Field_Catalog_Service::get_instance()->build_catalog($this->domain_key, true);

        $settings = DBVC_CC_Settings_Service::get_options();
        $settings['dbvc_cc_media_preview_thumbnail_enabled'] = 0;
        update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $settings);

        $result = DBVC_CC_Media_Candidate_Service::get_instance()->build_candidates($this->domain_key, $this->relative_path, true);
        $this->assertIsArray($result);
        $this->assertSame('built', $result['status']);

        $payload = is_array($result['media_candidates']) ? $result['media_candidates'] : [];
        $media_items = isset($payload['media_items']) && is_array($payload['media_items']) ? $payload['media_items'] : [];
        $this->assertNotEmpty($media_items);

        $has_disabled_preview_image = false;
        foreach ($media_items as $item) {
            if (! is_array($item)) {
                continue;
            }

            if ((string) ($item['media_kind'] ?? '') !== 'image') {
                continue;
            }

            if ((string) ($item['preview_status'] ?? '') === 'disabled_by_policy' && (string) ($item['preview_ref'] ?? '') === '') {
                $has_disabled_preview_image = true;
                break;
            }
        }

        $this->assertTrue($has_disabled_preview_image);
        $this->assertGreaterThanOrEqual(1, (int) ($payload['stats']['suppressed_preview_count'] ?? 0));
    }

    public function test_media_and_section_candidates_rebuild_when_source_artifact_is_newer(): void
    {
        DBVC_CC_Target_Field_Catalog_Service::get_instance()->build_catalog($this->domain_key, true);

        $section_initial = DBVC_CC_Section_Field_Candidate_Service::get_instance()->build_candidates($this->domain_key, $this->relative_path, true);
        $media_initial = DBVC_CC_Media_Candidate_Service::get_instance()->build_candidates($this->domain_key, $this->relative_path, true);
        $this->assertIsArray($section_initial);
        $this->assertIsArray($media_initial);

        $page_dir = trailingslashit($this->domain_dir) . $this->relative_path;
        $slug = 'home';
        $main_file = trailingslashit($page_dir) . $slug . '.json';
        $section_candidates_file = trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_SECTION_FIELD_CANDIDATES_V1_SUFFIX;
        $media_candidates_file = trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_MEDIA_CANDIDATES_V1_SUFFIX;

        $this->assertFileExists($main_file);
        $this->assertFileExists($section_candidates_file);
        $this->assertFileExists($media_candidates_file);

        $section_mtime_before = (int) filemtime($section_candidates_file);
        $media_mtime_before = (int) filemtime($media_candidates_file);

        sleep(1);
        $main_payload = json_decode((string) file_get_contents($main_file), true);
        $this->assertIsArray($main_payload);
        $main_payload['provenance']['content_hash'] = hash('sha256', 'phase37-home-updated');
        file_put_contents($main_file, wp_json_encode($main_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $section_after = DBVC_CC_Section_Field_Candidate_Service::get_instance()->get_candidates($this->domain_key, $this->relative_path, true);
        $media_after = DBVC_CC_Media_Candidate_Service::get_instance()->get_candidates($this->domain_key, $this->relative_path, true);

        $this->assertIsArray($section_after);
        $this->assertIsArray($media_after);
        $this->assertSame('built', $section_after['status']);
        $this->assertSame('built', $media_after['status']);

        $section_mtime_after = (int) filemtime($section_candidates_file);
        $media_mtime_after = (int) filemtime($media_candidates_file);
        $this->assertGreaterThan($section_mtime_before, $section_mtime_after);
        $this->assertGreaterThan($media_mtime_before, $media_mtime_after);
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
                'content_hash' => hash('sha256', 'phase37-home'),
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
                        'id' => 'dbvc_cc_sec_0001',
                        'heading' => 'Hero',
                        'images' => [
                            ['url' => 'https://phase37-example.test/wp-content/uploads/2026/03/gallery-1.jpg'],
                            ['url' => 'https://phase37-example.test/wp-content/uploads/2026/03/gallery-2.jpg'],
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
            'element_count' => 4,
            'truncated' => false,
            'processing' => [
                'is_partial' => false,
                'partial_reason' => '',
                'resume_marker' => new stdClass(),
            ],
            'elements' => [
                [
                    'element_id' => 'dbvc_cc_el_0001',
                    'tag' => 'img',
                    'text' => 'Hero image',
                    'sequence_index' => 1,
                    'attributes' => [
                        'src' => '/wp-content/uploads/2026/03/hero.jpg',
                        'alt' => 'Hero Alt',
                    ],
                ],
                [
                    'element_id' => 'dbvc_cc_el_0002',
                    'tag' => 'a',
                    'text' => 'Download brochure',
                    'sequence_index' => 2,
                    'link_target' => 'https://phase37-example.test/files/brochure.pdf',
                ],
                [
                    'element_id' => 'dbvc_cc_el_0003',
                    'tag' => 'iframe',
                    'text' => 'Watch video',
                    'sequence_index' => 3,
                    'attributes' => [
                        'src' => 'https://www.youtube.com/watch?v=abc123xyz',
                    ],
                ],
                [
                    'element_id' => 'dbvc_cc_el_0004',
                    'tag' => 'img',
                    'text' => 'Blocked private host image',
                    'sequence_index' => 4,
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
            'section_count' => 1,
            'processing' => [
                'is_partial' => false,
                'partial_reason' => '',
                'resume_marker' => new stdClass(),
            ],
            'sections' => [
                [
                    'section_id' => 'dbvc_cc_sec_0001',
                    'order' => 1,
                    'section_label_candidate' => 'Hero',
                    'start_sequence_index' => 1,
                    'end_sequence_index' => 4,
                    'element_ids' => ['dbvc_cc_el_0001', 'dbvc_cc_el_0002', 'dbvc_cc_el_0003', 'dbvc_cc_el_0004'],
                ],
            ],
        ];

        $this->write_json($page_dir . '/' . $slug . '.json', $main_artifact);
        $this->write_json($page_dir . '/' . $slug . '.elements.v2.json', $elements_v2);
        $this->write_json($page_dir . '/' . $slug . '.sections.v2.json', $sections_v2);
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
