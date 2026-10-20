<?php

class CapabilityLandscapeTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        if (! function_exists('dbvc_load_capability_landscape_manifest')) {
            require_once DBVC_PLUGIN_PATH . 'admin/capability-landscape.php';
        }
    }

    public function test_manifest_loads_and_contains_curated_records(): void
    {
        $manifest = dbvc_load_capability_landscape_manifest();

        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('records', $manifest);
        $this->assertGreaterThan(40, count($manifest['records']));
    }

    public function test_capability_landscape_requires_manage_options(): void
    {
        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber_id);
        $this->assertFalse(dbvc_can_view_capability_landscape());

        $administrator_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($administrator_id);
        $this->assertTrue(dbvc_can_view_capability_landscape());
    }

    public function test_verification_state_does_not_promote_scoped_or_test_evidence_to_live(): void
    {
        $live = dbvc_capability_landscape_verification([
            'verification' => [
                'live_runtime_verified' => true,
                'evidence_types' => ['focused_unit_test', 'same_checkout_wp_cli_execution'],
            ],
        ]);
        $scoped = dbvc_capability_landscape_verification([
            'verification' => [
                'live_runtime_verified' => false,
                'evidence_types' => ['same_checkout_dry_run_execution'],
            ],
        ]);
        $tested = dbvc_capability_landscape_verification([
            'test_refs' => ['tests/phpunit/ExampleTest.php'],
            'verification' => [
                'live_runtime_verified' => false,
                'evidence_types' => ['source_review', 'focused_unit_test'],
            ],
        ]);
        $reviewed = dbvc_capability_landscape_verification([
            'verification' => [
                'live_runtime_verified' => false,
                'evidence_types' => ['source_review'],
            ],
        ]);
        $missing = dbvc_capability_landscape_verification([]);

        $this->assertSame('live_verified', $live['state']);
        $this->assertSame('scoped_evidence', $scoped['state']);
        $this->assertSame('tested', $tested['state']);
        $this->assertSame('source_reviewed', $reviewed['state']);
        $this->assertSame('not_recorded', $missing['state']);
    }

    public function test_prepared_records_identify_cli_and_parity_opportunities(): void
    {
        $manifest = dbvc_load_capability_landscape_manifest();
        $this->assertIsArray($manifest);

        $records = dbvc_prepare_capability_landscape_records($manifest);
        $by_id = [];
        foreach ($records as $item) {
            $by_id[$item['record']['id']] = $item;
        }

        $this->assertArrayHasKey('addon.visual_editor.runtime', $by_id);
        $this->assertArrayHasKey('media.core.hydration', $by_id);
        $this->assertArrayHasKey('configuration.core.portability', $by_id);
        $this->assertArrayHasKey('ai.core.package_intake', $by_id);
        $this->assertContains('cli-ready', $by_id['cli.core.import']['agent_uses']);
        $this->assertContains('write-gated', $by_id['cli.core.import']['agent_uses']);
        $this->assertContains('safe-inspection', $by_id['addon.bricks.drift']['agent_uses']);
		$this->assertContains('parity-covered', $by_id['addon.bricks.drift']['agent_uses']);
		$this->assertNotContains('cli-candidate', $by_id['addon.bricks.drift']['agent_uses']);
		$this->assertSame('covered_elsewhere', $by_id['addon.bricks.drift']['opportunity']['disposition']);
		$this->assertSame('none', $by_id['addon.bricks.drift']['opportunity']['priority']);
		$this->assertArrayHasKey('cli.bricks.drift.inspect', $by_id);
		$this->assertContains('cli-ready', $by_id['cli.bricks.drift.inspect']['agent_uses']);
		$this->assertContains('safe-inspection', $by_id['cli.bricks.drift.inspect']['agent_uses']);
		$this->assertSame('read_only', $by_id['cli.bricks.drift.inspect']['safety']);
		$this->assertContains('parity-covered', $by_id['entity_editor.core.inspect']['agent_uses']);
		$this->assertNotContains('cli-candidate', $by_id['entity_editor.core.inspect']['agent_uses']);
		$this->assertSame('covered_elsewhere', $by_id['entity_editor.core.inspect']['opportunity']['disposition']);
		$this->assertArrayHasKey('cli.entity_editor.inspect', $by_id);
		$this->assertContains('cli-ready', $by_id['cli.entity_editor.inspect']['agent_uses']);
		$this->assertContains('safe-inspection', $by_id['cli.entity_editor.inspect']['agent_uses']);
		$this->assertSame('read_only', $by_id['cli.entity_editor.inspect']['safety']);
		$this->assertContains('parity-covered', $by_id['media.core.resolver_rules']['agent_uses']);
		$this->assertNotContains('cli-candidate', $by_id['media.core.resolver_rules']['agent_uses']);
		$this->assertSame('covered_elsewhere', $by_id['admin.core.capability_landscape']['opportunity']['disposition']);
		$this->assertArrayHasKey('cli.core.capabilities.inspect', $by_id);
		$this->assertContains('cli-ready', $by_id['cli.core.capabilities.inspect']['agent_uses']);
		$this->assertSame('read_only', $by_id['cli.core.capabilities.inspect']['safety']);
		$this->assertContains('parity-covered', $by_id['addon.bricks.control_plane']['agent_uses']);
		$this->assertNotContains('cli-candidate', $by_id['addon.bricks.control_plane']['agent_uses']);
		$this->assertSame('covered_elsewhere', $by_id['addon.bricks.control_plane']['opportunity']['disposition']);
		$this->assertSame('cli.bricks.doctor', $by_id['addon.bricks.control_plane']['record']['opportunity']['related_record']);
		$this->assertArrayHasKey('cli.bricks.doctor', $by_id);
		$this->assertContains('cli-ready', $by_id['cli.bricks.doctor']['agent_uses']);
		$this->assertContains('safe-inspection', $by_id['cli.bricks.doctor']['agent_uses']);
		$this->assertSame('read_only', $by_id['cli.bricks.doctor']['safety']);
		$this->assertContains('parity-covered', $by_id['addon.content_migration.runtime_guard']['agent_uses']);
		$this->assertNotContains('cli-candidate', $by_id['addon.content_migration.runtime_guard']['agent_uses']);
		$this->assertSame('cli.content_migration.runs.inspect', $by_id['addon.content_migration.runtime_guard']['record']['opportunity']['related_record']);
		$this->assertArrayHasKey('cli.content_migration.runs.inspect', $by_id);
		$this->assertContains('cli-ready', $by_id['cli.content_migration.runs.inspect']['agent_uses']);
		$this->assertContains('safe-inspection', $by_id['cli.content_migration.runs.inspect']['agent_uses']);
		$this->assertSame('read_only', $by_id['cli.content_migration.runs.inspect']['safety']);
		$this->assertContains('parity-covered', $by_id['proposal.core.inspect']['agent_uses']);
		$this->assertNotContains('cli-candidate', $by_id['proposal.core.inspect']['agent_uses']);
		$this->assertSame('read_only', $by_id['proposal.core.inspect']['safety']);
		$this->assertSame('cli.proposals.inspect', $by_id['proposal.core.inspect']['record']['opportunity']['related_record']);
		$this->assertArrayHasKey('cli.proposals.inspect', $by_id);
		$this->assertContains('cli-ready', $by_id['cli.proposals.inspect']['agent_uses']);
		$this->assertContains('safe-inspection', $by_id['cli.proposals.inspect']['agent_uses']);
		$this->assertSame('read_only', $by_id['cli.proposals.inspect']['safety']);
		$this->assertSame('live_verified', $by_id['cli.proposals.inspect']['verification']['state']);
		$this->assertTrue($by_id['cli.proposals.inspect']['verification']['live_runtime_verified']);
		$this->assertContains('same_checkout_wp_cli_execution', $by_id['cli.proposals.inspect']['verification']['evidence_types']);
		$this->assertSame('scoped_evidence', $by_id['media.core.resolver_rules']['verification']['state']);
		$this->assertFalse($by_id['media.core.resolver_rules']['verification']['live_runtime_verified']);
		$this->assertSame('scoped_evidence', $by_id['proposal.core.inspect']['verification']['state']);
		$this->assertSame('source_reviewed', $by_id['cli.core.import']['verification']['state']);
		$this->assertSame('scoped_evidence', $by_id['admin.core.capability_landscape']['verification']['state']);
		$this->assertFalse($by_id['admin.core.capability_landscape']['verification']['live_runtime_verified']);
		$this->assertContains('parity-deferred', $by_id['configuration.core.portability']['agent_uses']);
		$this->assertNotContains('cli-candidate', $by_id['configuration.core.portability']['agent_uses']);
		$this->assertSame('deferred', $by_id['configuration.core.portability']['opportunity']['disposition']);

        $stats = dbvc_capability_landscape_stats($records);
        $this->assertGreaterThanOrEqual(13, $stats['cli_commands']);
        // Source-reference routes remain discoverable but are excluded from active UI totals.
        $this->assertGreaterThanOrEqual(114, $stats['rest_registrations']);
		$this->assertGreaterThanOrEqual(10, $stats['opportunity_reviews']);
		$this->assertSame(0, $stats['cli_candidates']);
		$this->assertSame(6, $stats['live_verified']);
		$this->assertGreaterThanOrEqual(3, $stats['scoped_evidence']);
    }

    public function test_administrator_render_contains_filters_and_cli_inventory(): void
    {
        $administrator_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($administrator_id);

        ob_start();
        dbvc_render_capability_landscape_panel();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('id="dbvc-capability-landscape"', $html);
        $this->assertStringContainsString('id="dbvc-capability-agent-use"', $html);
		$this->assertStringContainsString('id="dbvc-capability-opportunity"', $html);
		$this->assertStringContainsString('id="dbvc-capability-verification"', $html);
		$this->assertStringContainsString('data-verification="live_verified"', $html);
		$this->assertStringContainsString('wp dbvc import', $html);
        $this->assertStringContainsString('Potential CLI parity', $html);
		$this->assertStringContainsString('Reviewed candidate', $html);
		$this->assertStringContainsString('Bounded proposal and entity summary commands', $html);
		$this->assertStringContainsString('Live verified', $html);
		$this->assertStringContainsString('Scoped evidence', $html);
		$this->assertStringContainsString('Verification details', $html);
		$this->assertStringContainsString('same checkout wp cli execution', strtolower($html));
		$this->assertStringContainsString('tests/phpunit/ProposalInspectionCliTest.php', $html);
		$this->assertStringContainsString('row.dataset.verification === verification.value', $html);
		$this->assertStringContainsString("verification.value = '';", $html);
        $this->assertStringContainsString('data-dbvc-capability-row', $html);
    }
}
