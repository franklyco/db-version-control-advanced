<?php

class BricksCliTest extends WP_UnitTestCase
{
    private const PACKAGE_ID = 'dbvc-bricks-cli-test';
    private const OPTION_ONE = 'dbvc_bricks_cli_test_one';
    private const OPTION_TWO = 'dbvc_bricks_cli_test_two';

    public function set_up(): void
    {
        parent::set_up();
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '1');
        update_option(self::OPTION_ONE, ['value' => 'local-one']);
        update_option(self::OPTION_TWO, ['value' => 'same']);
        update_option('dbvc_bricks_diff_path_rules', '{}');
        update_option('dbvc_bricks_artifact_ignore_rules', '{}');
        update_option('dbvc_bricks_artifact_mask_rules', '{}');
        update_option('dbvc_bricks_meta_ignore_rules', '{}');
        update_option('dbvc_bricks_meta_mask_rules', '{}');
        update_option('dbvc_bricks_package_' . self::PACKAGE_ID, $this->manifest());
    }

    public function tear_down(): void
    {
        delete_option('dbvc_bricks_package_' . self::PACKAGE_ID);
        delete_option(self::OPTION_ONE);
        delete_option(self::OPTION_TWO);
		delete_option(DBVC_Bricks_Addon::OPTION_UI_DIAGNOSTICS);
		delete_option('dbvc_bricks_role');
		delete_option(DBVC_Bricks_Addon::OPTION_VISIBILITY);
		delete_option('dbvc_bricks_read_only');
		delete_option('bricks_theme_styles');
		delete_option('bricks_components');
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '0');
        parent::tear_down();
    }

    public function test_stored_package_inspection_returns_compact_drift_envelope(): void
    {
        $result = DBVC_Bricks_CLI_Inspector::inspect([
            'package-id' => self::PACKAGE_ID,
            'limit' => 1,
            'max-changes' => 5,
        ]);

        $this->assertIsArray($result);
        $this->assertSame('stored_package', $result['source']);
        $this->assertSame(self::PACKAGE_ID, $result['package_id']);
        $this->assertSame(1, $result['counts']['clean']);
        $this->assertSame(1, $result['counts']['diverged']);
        $this->assertTrue($result['drift_detected']);
        $this->assertSame(0, $result['clean_with_path_differences']);
        $this->assertSame(1, $result['returned']);
        $this->assertSame(2, $result['total_matching']);
        $this->assertTrue($result['has_more']);
        $this->assertArrayNotHasKey('raw_compare', $result['artifacts'][0]);
        $this->assertArrayNotHasKey('payload', $result['artifacts'][0]);
        $this->assertArrayHasKey('path_difference_count', $result['artifacts'][0]);
    }

    public function test_exact_artifact_and_status_filters_bound_the_result(): void
    {
        $artifact_uid = 'option:' . self::OPTION_TWO;
        $result = DBVC_Bricks_CLI_Inspector::inspect([
            'package-id' => self::PACKAGE_ID,
            'artifact-uid' => $artifact_uid,
            'status' => 'clean',
        ]);

        $this->assertIsArray($result);
        $this->assertSame($artifact_uid, $result['artifact_scope']);
        $this->assertSame(1, $result['counts']['clean']);
        $this->assertSame(0, $result['non_clean']);
        $this->assertFalse($result['drift_detected']);
        $this->assertCount(1, $result['artifacts']);
        $this->assertSame('CLEAN', $result['artifacts'][0]['status']);
    }

    public function test_clean_fingerprint_reports_informational_path_differences_separately(): void
    {
        $package_id = 'dbvc-bricks-cli-clean-paths';
        $local_payload = ['value' => 'local-one'];
        $local_hash = DBVC_Bricks_Artifacts::fingerprint(
            DBVC_Bricks_Artifacts::canonicalize('bricks_global_classes', $local_payload)
        );
        update_option('dbvc_bricks_package_' . $package_id, [
            'package_id' => $package_id,
            'artifacts' => [[
                'artifact_uid' => 'option:' . self::OPTION_ONE,
                'artifact_type' => 'bricks_global_classes',
                'hash' => $local_hash,
                'payload' => ['value' => 'informational-difference'],
            ]],
        ]);

        $result = DBVC_Bricks_CLI_Inspector::inspect(['package-id' => $package_id]);
        delete_option('dbvc_bricks_package_' . $package_id);

        $this->assertIsArray($result);
        $this->assertSame(1, $result['counts']['clean']);
        $this->assertSame(0, $result['non_clean']);
        $this->assertSame(1, $result['clean_with_path_differences']);
        $this->assertSame(1, $result['artifacts'][0]['path_difference_count']);
        $this->assertFalse($result['drift_detected']);
    }

    public function test_source_and_read_only_guards_return_actionable_errors(): void
    {
        $missing_source = DBVC_Bricks_CLI_Inspector::inspect([]);
        $two_sources = DBVC_Bricks_CLI_Inspector::inspect([
            'package-id' => self::PACKAGE_ID,
            'file' => '/tmp/manifest.json',
        ]);
        $write_flag = DBVC_Bricks_CLI_Inspector::inspect([
            'package-id' => self::PACKAGE_ID,
            'apply' => true,
        ]);

        $this->assertWPError($missing_source);
        $this->assertSame('dbvc_bricks_cli_source_required', $missing_source->get_error_code());
        $this->assertWPError($two_sources);
        $this->assertSame('dbvc_bricks_cli_source_required', $two_sources->get_error_code());
        $this->assertWPError($write_flag);
        $this->assertSame('dbvc_bricks_cli_read_only', $write_flag->get_error_code());
    }

    public function test_disabled_addon_stops_before_package_inspection(): void
    {
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '0');
        $result = DBVC_Bricks_CLI_Inspector::inspect(['package-id' => self::PACKAGE_ID]);

        $this->assertWPError($result);
        $this->assertSame('dbvc_bricks_cli_disabled', $result->get_error_code());
    }

    public function test_doctor_returns_bounded_control_plane_and_schema_evidence(): void
    {
        update_option('dbvc_bricks_role', 'client');
        update_option(DBVC_Bricks_Addon::OPTION_VISIBILITY, 'submenu_only');
        update_option('dbvc_bricks_read_only', '1');
        update_option('bricks_theme_styles', [['id' => 'style-one']]);
        update_option('bricks_components', [['name' => 'Card', 'slug' => 'card']]);

        $result = DBVC_Bricks_CLI_Doctor::inspect([]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertTrue($result['status']['enabled']);
        $this->assertSame('client', $result['status']['role']);
        $this->assertSame('submenu_only', $result['status']['visibility']);
        $this->assertTrue($result['status']['read_only']);
        $this->assertSame(DBVC_Bricks_Addon::UI_CONTRACT_VERSION, $result['ui_contract']['ui_contract_version']);
        $this->assertTrue($result['schema']['theme_styles']['present']);
        $this->assertTrue($result['schema']['components']['present']);
        $this->assertArrayNotHasKey('site_uid', $result['status']);
        $this->assertArrayNotHasKey('site_name', $result['status']);
        $this->assertArrayNotHasKey('home_url', $result['status']);
        $this->assertArrayNotHasKey('site_url', $result['status']);
        $this->assertArrayNotHasKey('items', $result);
        $this->assertArrayNotHasKey('package_delivery', $result);
    }

    public function test_doctor_does_not_read_or_change_stored_ui_diagnostics(): void
    {
        $diagnostics = [[
            'event_type' => 'secret-test-event',
            'details' => ['token' => 'must-not-be-returned'],
        ]];
        update_option(DBVC_Bricks_Addon::OPTION_UI_DIAGNOSTICS, $diagnostics);

        $result = DBVC_Bricks_CLI_Doctor::inspect([]);

        $this->assertIsArray($result);
        $this->assertSame($diagnostics, get_option(DBVC_Bricks_Addon::OPTION_UI_DIAGNOSTICS));
        $encoded = wp_json_encode($result);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('secret-test-event', $encoded);
        $this->assertStringNotContainsString('must-not-be-returned', $encoded);
    }

    public function test_doctor_rejects_unknown_and_write_like_arguments_before_inspection(): void
    {
        foreach (['diagnostics', 'telemetry', 'settings', 'package', 'fleet', 'remote', 'apply', 'restore', 'proposal'] as $flag) {
            $result = DBVC_Bricks_CLI_Doctor::inspect([$flag => true]);
            $this->assertWPError($result, $flag);
            $this->assertSame('dbvc_bricks_doctor_read_only', $result->get_error_code(), $flag);
            $this->assertStringContainsString('--' . $flag, $result->get_error_message(), $flag);
        }

		$invalid_format = DBVC_Bricks_CLI_Doctor::inspect(['format' => 'xml']);
		$this->assertWPError($invalid_format);
		$this->assertSame('dbvc_bricks_doctor_format_invalid', $invalid_format->get_error_code());
    }

    public function test_doctor_table_rows_are_fixed_and_scalar_only(): void
    {
        $result = DBVC_Bricks_CLI_Doctor::inspect([]);
        $this->assertIsArray($result);

        $rows = DBVC_Bricks_CLI_Doctor::table_rows($result);

        $this->assertCount(7, $rows);
        $this->assertSame(
            ['addon_status', 'operating_mode', 'ui_contract', 'schema_theme_styles', 'schema_components', 'runtime_health', 'deprecations'],
            array_column($rows, 'check')
        );
        foreach ($rows as $row) {
            $this->assertSame(['check', 'status', 'detail'], array_keys($row));
            $this->assertIsString($row['detail']);
        }
    }

    public function test_registration_and_synopsis_remain_read_only(): void
    {
        $source = file_get_contents(DBVC_PLUGIN_PATH . 'commands/class-bricks-cli.php');

        $this->assertIsString($source);
        $this->assertStringContainsString("WP_CLI::add_command('dbvc bricks', 'DBVC_WP_CLI_Bricks')", $source);
		$this->assertStringContainsString('public function doctor($args, $assoc_args)', $source);
		$this->assertStringContainsString('[--fail-on-warnings]', $source);
        $this->assertStringContainsString('[--fail-on-drift]', $source);
        $this->assertStringNotContainsString('[--apply]', $source);
        $this->assertStringNotContainsString('[--write]', $source);
        $this->assertStringNotContainsString('[--include-raw-compare]', $source);
		$this->assertStringNotContainsString('DBVC_Bricks_Addon::get_diagnostics()', $source);
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        return [
            'package_id' => self::PACKAGE_ID,
            'schema_version' => DBVC_Bricks_Addon::UI_CONTRACT_VERSION,
            'artifacts' => [
                [
                    'artifact_uid' => 'option:' . self::OPTION_ONE,
                    'artifact_type' => 'bricks_global_classes',
                    'payload' => ['value' => 'golden-one'],
                ],
                [
                    'artifact_uid' => 'option:' . self::OPTION_TWO,
                    'artifact_type' => 'bricks_components',
                    'payload' => ['value' => 'same'],
                ],
            ],
        ];
    }
}
