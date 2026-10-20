<?php

class PluginBootstrapSmokeTest extends WP_UnitTestCase
{
    /**
     * @group bootstrap-smoke
     */
    public function test_plugin_bootstrap_loads_core_services(): void
    {
        $this->assertTrue(defined('DBVC_PHPUNIT'));
        $this->assertTrue(defined('DBVC_PLUGIN_FILE'));
        $this->assertTrue(defined('DBVC_PLUGIN_PATH'));
        $this->assertSame(dirname(__DIR__, 2) . '/db-version-control.php', DBVC_PLUGIN_FILE);
        $this->assertSame(dirname(__DIR__, 2) . '/', DBVC_PLUGIN_PATH);
        $this->assertTrue(class_exists('DBVC_Admin_App'));
        $this->assertTrue(class_exists('DBVC_Backup_Manager'));
        $this->assertTrue(class_exists('DBVC_Snapshot_Manager'));
        $this->assertTrue(class_exists('DBVC_Sync_Posts'));
        $this->assertTrue(class_exists('DBVC_Entity_Editor_App'));
        $this->assertTrue(class_exists('DBVC_Bricks_Addon'));
        $this->assertTrue(method_exists('DBVC_Admin_App', 'recapture_proposal_snapshots'));
        $this->assertTrue(method_exists('DBVC_Snapshot_Manager', 'capture_post_snapshot_result'));
        $this->assertTrue(method_exists('DBVC_Snapshot_Manager', 'capture_term_snapshot_result'));
        $this->assertTrue(method_exists('DBVC_Snapshot_Manager', 'inspect_post_snapshot'));
        $this->assertTrue(method_exists('DBVC_Snapshot_Manager', 'inspect_term_snapshot'));
    }

    /**
     * @group bootstrap-smoke
     */
    public function test_proposal_diff_routes_register(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        do_action('rest_api_init');

        $routes = rest_get_server()->get_routes();

        $this->assertArrayHasKey('/dbvc/v1/proposals', $routes);
        $this->assertArrayHasKey('/dbvc/v1/fixtures/upload', $routes);
        $this->assertArrayHasKey('/dbvc/v1/proposals/(?P<proposal_id>[^/]+)/entities', $routes);
        $this->assertArrayHasKey('/dbvc/v1/proposals/(?P<proposal_id>[^/]+)/readiness', $routes);
        $this->assertArrayHasKey('/dbvc/v1/proposals/(?P<proposal_id>[^/]+)/entities/(?P<vf_object_uid>[^/]+)/snapshot', $routes);
        $this->assertArrayHasKey('/dbvc/v1/proposals/(?P<proposal_id>[^/]+)/snapshot', $routes);
        $this->assertArrayHasKey('/dbvc/v1/proposals/(?P<proposal_id>[^/]+)/apply', $routes);
        $this->assertArrayHasKey('/dbvc/v1/proposals/(?P<proposal_id>[^/]+)/resolver', $routes);
    }

    /**
     * @group bootstrap-smoke
     */
    public function test_wp_cli_commands_register_after_command_classes(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/commands/class-wp-cli-commands.php');
        $this->assertIsString($source);

        $commands = [
            'DBVC_WP_CLI_Commands'       => "WP_CLI::add_command( 'dbvc', 'DBVC_WP_CLI_Commands' )",
            'DBVC_WP_CLI_Proposals'      => "WP_CLI::add_command( 'dbvc proposals', 'DBVC_WP_CLI_Proposals' )",
            'DBVC_WP_CLI_Resolver_Rules' => "WP_CLI::add_command( 'dbvc resolver-rules', 'DBVC_WP_CLI_Resolver_Rules' )",
        ];

        foreach ($commands as $class_name => $registration) {
            $class_position = strpos($source, 'class ' . $class_name);
            $registration_position = strpos($source, $registration);

            $this->assertNotFalse($class_position, $class_name . ' must be declared.');
            $this->assertNotFalse($registration_position, $class_name . ' must be registered.');
            $this->assertGreaterThan(
                $class_position,
                $registration_position,
                $class_name . ' must be declared before WP-CLI registration.'
            );
        }

        $proposal_list_alias_position = strpos($source, '@subcommand list');
        $proposal_list_method_position = strpos($source, 'public function list_');
        $this->assertNotFalse($proposal_list_alias_position);
        $this->assertNotFalse($proposal_list_method_position);
        $this->assertGreaterThan($proposal_list_alias_position, $proposal_list_method_position);

        $media_commands_file = dirname(__DIR__, 2) . '/commands/class-media-hydration-cli.php';
        if (file_exists($media_commands_file)) {
            $media_source = file_get_contents($media_commands_file);
            $this->assertIsString($media_source);

            $media_class_position = strpos($media_source, 'class DBVC_WP_CLI_Media');
            $media_registration_position = strpos($media_source, "WP_CLI::add_command('dbvc media', 'DBVC_WP_CLI_Media')");

            $this->assertNotFalse($media_class_position);
            $this->assertNotFalse($media_registration_position);
            $this->assertGreaterThan($media_class_position, $media_registration_position);
        }
    }
}
