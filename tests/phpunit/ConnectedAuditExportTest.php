<?php

use Dbvc\AgencyControl\Storage\ReleaseStore;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;

/**
 * M9 slice 7a — the agency audit export: one read-only JSON snapshot that
 * bundles the event-sourced governance stores (environments, definitions,
 * releases, approvals, rollouts, framework rollouts, reviews, overrides). It
 * never writes and deliberately excludes the main plugin's dbvc_activity_log
 * (which records no agency actions).
 *
 * @group connected-environments
 */
class ConnectedAuditExportTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        add_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        delete_option(HubSchema::OPTION_SCHEMA_VERSION);
        HubSchema::reset_memo();
        DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
    }

    public function tear_down(): void
    {
        DBVC_Agency_Control_Addon::save_settings([]);
        remove_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        HubSchema::reset_memo();
        parent::tear_down();
    }

    public function savepoint_strategy(): string
    {
        return 'savepoint';
    }

    public function test_audit_export_bundles_the_governance_stores(): void
    {
        // Seed one release so a section has real content.
        (new ReleaseStore())->create(
            ['release_uid' => 'rel-audit-1', 'agency_id' => 'ag-1', 'client_id' => 'cl-1', 'source_environment_id' => 'src-1', 'source_epoch' => 'ep-1'],
            [['domain' => 'bricks.global_class', 'instance_uid' => 'u1', 'profile' => 'p-v1', 'after_hash' => str_repeat('a', 64)]]
        );

        $bundle = \DBVC_Agency_CLI_Inspector::audit_export([]);
        $this->assertIsArray($bundle, is_wp_error($bundle) ? $bundle->get_error_message() : '');
        $this->assertSame('dbvc_agency_audit_export', $bundle['package_type']);
        $this->assertSame(1, $bundle['package_schema_version']);
        $this->assertNotSame('', (string) $bundle['generated_at']);

        $sections = $bundle['sections'];
        foreach (['environments', 'definitions', 'releases', 'approvals', 'rollouts', 'framework_rollouts', 'reviews', 'overrides'] as $name) {
            $this->assertArrayHasKey($name, $sections, "section $name present");
            $this->assertArrayNotHasKey('error', (array) $sections[$name], "section $name read cleanly");
        }
        // The seeded release is in the bundle.
        $this->assertSame(1, $sections['releases']['returned']);
        $this->assertSame('rel-audit-1', $sections['releases']['releases'][0]['release_uid']);
    }

    public function test_filters_are_echoed_in_the_bundle_header(): void
    {
        $bundle = \DBVC_Agency_CLI_Inspector::audit_export(['agency' => 'ag-9', 'limit' => 5, 'ignored' => 'x']);
        $this->assertSame('ag-9', $bundle['filters']['agency']);
        $this->assertSame(5, $bundle['filters']['limit']);
        $this->assertArrayNotHasKey('ignored', $bundle['filters'], 'Only recognised filters are echoed.');
    }
}
