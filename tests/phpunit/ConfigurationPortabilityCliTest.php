<?php

use Dbvc\ConfigurationPortability\Registry;

class ConfigurationPortabilityCliTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        Registry::reset();
    }

    public function tear_down(): void
    {
        Registry::reset();
        parent::tear_down();
    }

    public function test_domains_returns_bounded_schema_counts(): void
    {
        $rows = DBVC_Configuration_Portability_CLI_Inspector::domains([]);

        $this->assertIsArray($rows);
        $this->assertGreaterThanOrEqual(11, count($rows));
        $this->assertSame(
            ['key', 'label', 'version', 'groups', 'fields', 'portable', 'exclude', 'redact', 'prompt', 'replace', 'keep_existing', 'advanced', 'sensitive'],
            array_keys($rows[0])
        );
        $this->assertNotContains('get_current_values', array_keys($rows[0]));
        $this->assertNotContains('values', array_keys($rows[0]));
    }

    public function test_exact_domain_filter_and_missing_domain_error(): void
    {
        $rows = DBVC_Configuration_Portability_CLI_Inspector::domains(['domain' => 'visual_editor']);
        $missing = DBVC_Configuration_Portability_CLI_Inspector::domains(['domain' => 'does_not_exist']);

        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame('visual_editor', $rows[0]['key']);
        $this->assertWPError($missing);
        $this->assertSame('dbvc_config_cli_domain_missing', $missing->get_error_code());
    }

    public function test_status_aggregates_metadata_without_current_values(): void
    {
        update_option('dbvc_bricks_api_secret', 'dbvc-phase35-secret-value');
        update_option('dbvc_sync_path', 'dbvc-phase35-private-path');

        $status = DBVC_Configuration_Portability_CLI_Inspector::status([]);

        $this->assertIsArray($status);
        $this->assertSame('dbvc_configuration_portability', $status['feature']);
        $this->assertGreaterThanOrEqual(11, $status['domains']);
        $this->assertGreaterThan(0, $status['fields']);
        $this->assertSame('no', $status['current_values_read']);
        $this->assertSame('no', $status['writer_services_invoked']);
        $encoded = wp_json_encode($status);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('dbvc-phase35-secret-value', $encoded);
        $this->assertStringNotContainsString('dbvc-phase35-private-path', $encoded);

        delete_option('dbvc_bricks_api_secret');
        delete_option('dbvc_sync_path');
    }

    public function test_unknown_write_like_and_invalid_format_arguments_fail_closed(): void
    {
        foreach (['export', 'download', 'upload', 'diff', 'apply', 'backup', 'rollback', 'values', 'secrets'] as $flag) {
            $result = DBVC_Configuration_Portability_CLI_Inspector::domains([$flag => true]);
            $this->assertWPError($result, $flag);
            $this->assertSame('dbvc_config_cli_read_only', $result->get_error_code(), $flag);
            $this->assertStringContainsString('--' . $flag, $result->get_error_message(), $flag);
        }

        $invalid = DBVC_Configuration_Portability_CLI_Inspector::status(['format' => 'xml']);
        $this->assertWPError($invalid);
        $this->assertSame('dbvc_config_cli_format_invalid', $invalid->get_error_code());
    }

    public function test_registration_and_source_contract_remain_metadata_only(): void
    {
        $source = file_get_contents(DBVC_PLUGIN_PATH . 'commands/class-configuration-portability-cli.php');
        $router = file_get_contents(DBVC_PLUGIN_PATH . 'commands/class-wp-cli-commands.php');

        $this->assertIsString($source);
        $this->assertIsString($router);
        $this->assertStringContainsString("WP_CLI::add_command('dbvc config', 'DBVC_WP_CLI_Configuration_Portability')", $source);
        $this->assertStringContainsString('public function domains($args, $assoc_args)', $source);
        $this->assertStringContainsString('public function status($args, $assoc_args)', $source);
        $this->assertStringNotContainsString('->get_current_values(', $source);
        $this->assertStringNotContainsString('->export(', $source);
        $this->assertStringNotContainsString('->apply(', $source);
        $this->assertStringNotContainsString('->rollback(', $source);
        $this->assertStringContainsString("require_once __DIR__ . '/class-configuration-portability-cli.php'", $router);
    }
}
