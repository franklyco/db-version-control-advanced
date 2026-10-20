<?php

/**
 * R3-BX — CurationExporter output contract.
 *
 * Verifies the JSON + MD artifacts land in the configured directory,
 * only `include` decisions are emitted, the `unlocks_at` bucket comes
 * from FieldCurationRecommender::deriveUnlocksAt, and counts +
 * unlocks_summary aggregate correctly.
 */
final class VisualEditorCurationExporterTest extends WP_UnitTestCase
{
    /**
     * @var string
     */
    private $tempDir = '';

    public function set_up(): void
    {
        parent::set_up();
        $this->tempDir = sys_get_temp_dir() . '/dbvc-ve-curation-test-' . uniqid('', true);
    }

    public function tear_down(): void
    {
        if ($this->tempDir !== '' && is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tempDir);
        }
        parent::tear_down();
    }

    public function test_export_writes_include_records_only_and_derives_unlocks_summary(): void
    {
        $candidates = [
            $this->cand('option:site-settings:tagline', 'text'),
            $this->cand('option:advanced-settings:brand_color_palette>vertical_global_palette>colorPrimary', 'color_picker'),
            $this->cand('option:site-settings:main_logo', 'image'),
            $this->cand('option:site-settings:hours', 'repeater'),
        ];
        $decisions = [
            'option:site-settings:tagline' => ['decision' => 'include', 'category' => 'Brand', 'client_priority' => 'must'],
            'option:advanced-settings:brand_color_palette>vertical_global_palette>colorPrimary' => ['decision' => 'include', 'category' => 'Brand'],
            'option:site-settings:main_logo' => ['decision' => 'include', 'category' => 'Brand'],
            'option:site-settings:hours' => ['decision' => 'defer'],
        ];

        $result = $this->makeExporter()->export($candidates, $decisions, 'unit-test');
        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(3, $result['include_count']);
        $this->assertFileExists($result['json_path']);
        $this->assertFileExists($result['md_path']);

        $payload = json_decode(file_get_contents($result['json_path']), true);
        $this->assertSame('dbvc.ve.curation.v1', $payload['schema']);
        $this->assertSame('unit-test', $payload['source_site']);
        $this->assertSame(3, $payload['counts']['include']);
        $this->assertSame(1, $payload['counts']['defer']);
        $this->assertSame(0, $payload['counts']['ignore']);
        $this->assertSame(
            ['R5.1' => 1, 'R5.2+color_picker' => 1, 'R5.3' => 1],
            $payload['unlocks_summary']
        );

        $record_ids = array_column($payload['records'], 'id');
        sort($record_ids);
        $this->assertSame(
            [
                'advanced-settings__brand_color_palette__vertical_global_palette__colorPrimary',
                'site-settings__main_logo',
                'site-settings__tagline',
            ],
            $record_ids
        );
    }

    public function test_export_creates_the_destination_directory_when_missing(): void
    {
        $this->assertDirectoryDoesNotExist($this->tempDir);
        $result = $this->makeExporter()->export([], [], '');
        $this->assertTrue($result['ok'], $result['message']);
        $this->assertDirectoryExists($this->tempDir);
    }

    public function test_export_of_empty_include_set_still_writes_a_valid_scaffold(): void
    {
        $result = $this->makeExporter()->export([], [], 'empty-run');
        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['include_count']);

        $payload = json_decode(file_get_contents($result['json_path']), true);
        $this->assertSame([], $payload['records']);
        $md = file_get_contents($result['md_path']);
        $this->assertStringContainsString('_(no records marked include yet)_', $md);
    }

    /**
     * @param string $id
     * @param string $field_type
     * @return array<string, mixed>
     */
    private function cand($id, $field_type)
    {
        $parts = explode(':', $id, 3);
        return [
            'id' => $id,
            'options_page' => $parts[1] ?? '',
            'field_name_path' => $parts[2] ?? '',
            'field_key' => 'field_' . md5($id),
            'field_type' => $field_type,
            'field_label' => 'Label ' . $id,
            'group_title' => 'Group',
            'ancestor_labels' => [],
        ];
    }

    /**
     * @return \Dbvc\VisualEditor\Curation\CurationExporter
     */
    private function makeExporter()
    {
        return new \Dbvc\VisualEditor\Curation\CurationExporter(
            $this->tempDir,
            new \Dbvc\VisualEditor\Curation\FieldCurationRecommender()
        );
    }
}
