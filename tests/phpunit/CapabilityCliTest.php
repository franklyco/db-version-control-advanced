<?php

class CapabilityCliTest extends WP_UnitTestCase
{
    public function test_repository_loads_packaged_manifest_and_snapshot(): void
    {
        $manifest = DBVC_Capability_Repository::load_manifest();
        $snapshot = DBVC_Capability_Repository::load_snapshot();

        $this->assertIsArray($manifest);
        $this->assertIsArray($snapshot);
        $this->assertSame('strict', $manifest['coverage_enforcement']);
        $this->assertGreaterThanOrEqual(47, count($manifest['records']));
        $this->assertGreaterThanOrEqual(16, $snapshot['counts']['cli_commands']);
    }

    public function test_repository_reports_completed_reviewed_cli_candidate_queue(): void
    {
        $manifest = DBVC_Capability_Repository::load_manifest();
        $this->assertIsArray($manifest);

        $records = DBVC_Capability_Repository::filter_records(
            $manifest['records'],
            ['opportunity' => 'candidate']
        );
        $ids = array_map(
            static function ($record) {
                return $record['id'];
            },
            $records
        );

        $this->assertSame([], $ids);
        foreach ($records as $record) {
            $this->assertNotEmpty($record['opportunity']['candidate_scope'], $record['id']);
            $this->assertNotEmpty($record['opportunity']['excluded_operations'], $record['id']);
        }

        $high_priority = DBVC_Capability_Repository::filter_records(
            $manifest['records'],
            [
                'opportunity' => 'candidate',
                'priority' => 'high',
            ]
        );
        $this->assertSame([], array_column($high_priority, 'id'));

		$bricks_control_plane = DBVC_Capability_Repository::find_record($manifest['records'], 'addon.bricks.control_plane');
		$this->assertIsArray($bricks_control_plane);
		$this->assertSame('covered_elsewhere', $bricks_control_plane['opportunity']['disposition']);
		$this->assertSame('cli.bricks.doctor', $bricks_control_plane['opportunity']['related_record']);

		$content_migration = DBVC_Capability_Repository::find_record($manifest['records'], 'addon.content_migration.runtime_guard');
		$this->assertIsArray($content_migration);
		$this->assertSame('covered_elsewhere', $content_migration['opportunity']['disposition']);
		$this->assertSame('cli.content_migration.runs.inspect', $content_migration['opportunity']['related_record']);

		$proposal_inspection = DBVC_Capability_Repository::find_record($manifest['records'], 'proposal.core.inspect');
		$this->assertIsArray($proposal_inspection);
		$this->assertSame('read_only', $proposal_inspection['safety']['classification']);
		$this->assertSame('covered_elsewhere', $proposal_inspection['opportunity']['disposition']);
		$this->assertSame('cli.proposals.inspect', $proposal_inspection['opportunity']['related_record']);

		$proposal_cli = DBVC_Capability_Repository::find_record($manifest['records'], 'cli.proposals.inspect');
		$this->assertIsArray($proposal_cli);
		$this->assertSame('read_only', $proposal_cli['safety']['classification']);
		$this->assertSame('not_recommended', $proposal_cli['opportunity']['disposition']);

        $configuration_portability = DBVC_Capability_Repository::find_record($manifest['records'], 'configuration.core.portability');
        $this->assertIsArray($configuration_portability);
        $this->assertSame('covered_elsewhere', $configuration_portability['opportunity']['disposition']);
        $this->assertSame('cli.configuration.portability.inspect', $configuration_portability['opportunity']['related_record']);

        $configuration_cli = DBVC_Capability_Repository::find_record($manifest['records'], 'cli.configuration.portability.inspect');
        $this->assertIsArray($configuration_cli);
        $this->assertSame('read_only', $configuration_cli['safety']['classification']);
        $this->assertSame('not_recommended', $configuration_cli['opportunity']['disposition']);
    }

    public function test_repository_finds_and_flattens_one_record(): void
    {
        $manifest = DBVC_Capability_Repository::load_manifest();
        $this->assertIsArray($manifest);

        $record = DBVC_Capability_Repository::find_record($manifest['records'], 'admin.core.capability_landscape');
        $this->assertIsArray($record);
        $row = DBVC_Capability_Repository::record_row($record);

        $this->assertSame('admin.core.capability_landscape', $row['id']);
        $this->assertSame('read_only', $row['safety']);
        $this->assertStringContainsString('admin', $row['surfaces']);
    }

    public function test_repository_reports_strict_surface_ownership(): void
    {
        $manifest = DBVC_Capability_Repository::load_manifest();
        $snapshot = DBVC_Capability_Repository::load_snapshot();
        $this->assertIsArray($manifest);
        $this->assertIsArray($snapshot);

        $coverage = DBVC_Capability_Repository::coverage($manifest, $snapshot);

        $this->assertGreaterThanOrEqual(396, $coverage['discovered']);
        $this->assertSame($coverage['discovered'], $coverage['mapped']);
        $this->assertSame(0, $coverage['unmapped']);
        $this->assertSame(0, $coverage['unknown']);
        $this->assertSame(0, $coverage['duplicates']);
    }

    public function test_read_only_recipes_reference_known_manifest_records(): void
    {
        $manifest = DBVC_Capability_Repository::load_manifest();
        $this->assertIsArray($manifest);
        $known_ids = [];
        foreach ($manifest['records'] as $record) {
            $known_ids[$record['id']] = true;
        }

        $contents = file_get_contents(DBVC_PLUGIN_PATH . 'docs/agents/RECIPES.md');
        $this->assertIsString($contents);
        $matched = preg_match_all(
            '/<!-- recipe:\s*([a-z0-9-]+)\s*-->\s*<!-- safety:\s*([a-z_]+)\s*-->\s*<!-- capability-records:\s*([^>]+?)\s*-->/',
            $contents,
            $matches,
            PREG_SET_ORDER
        );

        $this->assertSame(8, $matched);
        foreach ($matches as $match) {
            $this->assertSame('read_only', $match[2], $match[1]);
            $record_ids = array_values(array_filter(array_map('trim', explode(',', $match[3]))));
            $this->assertContains('cli.core.capabilities.inspect', $record_ids, $match[1]);
            foreach ($record_ids as $record_id) {
                $this->assertArrayHasKey($record_id, $known_ids, $match[1]);
            }
        }
    }
}
