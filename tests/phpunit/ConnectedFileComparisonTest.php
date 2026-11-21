<?php

use Dbvc\Connected\Adapters\BricksOptionCollectionObserver;
use Dbvc\Connected\Adapters\DomainRegistry;
use Dbvc\Connected\Inspection\FileComparison;
use Dbvc\Connected\Storage\JobStore;
use Dbvc\Connected\Storage\Schema;

/**
 * CR-5 hubless file mode: `objects-export` on one site, `compare-file` on the
 * other, no hub and no transport. One site plays both roles here: the bundle
 * is exported, the option is edited as the other site would differ, and the
 * comparison reads the edited projections against the bundle.
 *
 * @group connected-environments
 */
class ConnectedFileComparisonTest extends WP_UnitTestCase
{
    private const CLASSES = 'bricks_global_classes';
    private const VARIABLES = 'bricks_global_variables';

    /**
     * @var array<int, string>
     */
    private $temp_files = [];

    public function set_up(): void
    {
        parent::set_up();
        add_filter('dbvc_connected_transaction_strategy', [$this, 'savepoint_strategy']);
        add_filter('dbvc_connected_bricks_available', '__return_true');
        add_filter('dbvc_connected_processing_delay', '__return_zero');
        add_filter('dbvc_should_export_on_option_update', '__return_false');
        add_filter('pre_http_request', [$this, 'trap_http'], 10, 3);

        register_post_type('service', ['public' => true, 'supports' => ['title', 'editor', 'custom-fields']]);
        delete_option(self::CLASSES);
        delete_option(self::VARIABLES);
        delete_option(Schema::OPTION_SCHEMA_VERSION);
        Schema::reset_memo();
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1']);
        $this->assertSame('ready', DBVC_Connected_Environments_Addon::get_gate_state());
        $this->assertSame(3, (new JobStore())->counts()['total']);
        $this->assertSame(3, $this->run_worker()['acknowledged']);
    }

    public function tear_down(): void
    {
        foreach ($this->temp_files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        unregister_post_type('service');
        DBVC_Connected_Environments_Addon::save_settings([]);
        remove_filter('dbvc_connected_transaction_strategy', [$this, 'savepoint_strategy']);
        remove_filter('dbvc_connected_bricks_available', '__return_true');
        remove_filter('dbvc_connected_processing_delay', '__return_zero');
        remove_filter('dbvc_should_export_on_option_update', '__return_false');
        remove_filter('pre_http_request', [$this, 'trap_http'], 10);
        delete_option(self::CLASSES);
        delete_option(self::VARIABLES);
        Schema::reset_memo();
        parent::tear_down();
    }

    public function savepoint_strategy(): string
    {
        return 'savepoint';
    }

    public function trap_http($preempt, $args, $url)
    {
        unset($preempt, $args);
        $this->fail('Unexpected HTTP request during file-mode activity: ' . (string) $url);
    }

    public function test_export_then_compare_reports_differences_without_a_hub(): void
    {
        update_option(self::CLASSES, $this->classes_fixture());
        $this->run_worker();

        // ---- export half (the "staging" side) ------------------------------------------
        $out = $this->temp_file('staging-objects.json');
        $written = DBVC_Connected_CLI_Inspector::objects_export(['domains' => DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS, 'out' => $out]);
        $this->assertIsArray($written, is_wp_error($written) ? $written->get_error_message() : '');
        $this->assertSame(3, $written['objects']);
        $this->assertSame(0, $written['skipped']);
        $this->assertFileExists($out);
        $bundle = json_decode((string) file_get_contents($out), true);
        $this->assertSame(FileComparison::FORMAT, $bundle['format']);
        $this->assertSame(home_url(), $bundle['site']);
        $this->assertSame(3, $bundle['domains'][DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS]['count']);
        $this->assertNotContains(BricksOptionCollectionObserver::ORDER_INSTANCE_UID, array_column($bundle['objects'], 'instance_uid'), 'The collection-order projection is not an object.');
        $by_name = [];
        foreach ($bundle['objects'] as $object) {
            $by_name[$object['display_name']] = $object;
        }
        $this->assertSame(['--bg-gradient-light', '--flex-col', '--flex-row'], $this->sorted(array_keys($by_name)));
        $this->assertSame('row', $by_name['--flex-row']['body']['settings']['_direction']);
        $this->assertSame($by_name['--flex-row']['semantic_hash'], \Dbvc\ConnectedProtocol\Canonicalizer::hash(\Dbvc\ConnectedProtocol\Canonicalizer::encode($by_name['--flex-row']['body'])), 'The body is the recorded canonical projection: its hash is the stored semantic hash.');

        $refused = DBVC_Connected_CLI_Inspector::objects_export(['out' => $out]);
        $this->assertSame('dbvc_connected_file_exists', $refused->get_error_code());
        $this->assertSame('dbvc_connected_cli_domain', DBVC_Connected_CLI_Inspector::objects_export(['domains' => 'wp.nope'])->get_error_code());

        // Nothing differs yet: the same site compared with its own export matches everywhere.
        $same = (new FileComparison())->compare($bundle);
        $this->assertSame(['only_in_file' => 0, 'differs' => 0, 'only_here' => 0, 'same' => 3], $same['counts']);
        $this->assertSame('Both sites match (3 objects).', $same['summary']);
        $this->assertSame([], $same['items']);

        // ---- the "LocalWP" side diverges: one edit, one removal, one addition ------------
        $classes = $this->classes_fixture();
        $classes[1]['settings']['_direction'] = 'column';          // --flex-row edited here
        $classes[1]['settings']['api_key'] = 'shh';                 // and given a secret-looking key
        unset($classes[2]);                                         // --bg-gradient-light removed here
        $classes[] = ['id' => 'ddd444', 'name' => '--local-only', 'settings' => ['_display' => 'grid']];
        update_option(self::CLASSES, array_values($classes));
        $this->run_worker();

        $result = DBVC_Connected_CLI_Inspector::compare_file(['file' => $out]);
        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertSame(['only_in_file' => 1, 'differs' => 1, 'only_here' => 1, 'same' => 1], $result['counts']);
        $this->assertSame('3 objects to look at: 1 differs, 1 only in the export, 1 only on this site; 1 match.', $result['summary']);
        $this->assertTrue($result['values_included']);
        $this->assertSame('compared', $result['domains'][DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS]['status']);
        $this->assertSame(3, $result['domains'][DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS]['in_file']);
        $this->assertSame(3, $result['domains'][DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS]['here'], 'The removed class is a tombstone here, not an object.');

        $this->assertSame(['differs', 'only_in_file', 'only_here'], array_column($result['items'], 'label'));
        $this->assertSame(['--flex-row', '--bg-gradient-light', '--local-only'], array_column($result['items'], 'display_name'));
        $edited = $result['items'][0];
        $this->assertSame('uid', $edited['matched_by']);
        $this->assertSame($by_name['--flex-row']['instance_uid'], $edited['instance_uid'], 'The portable UID survives the edit.');
        $this->assertSame(2, $edited['path_count']);
        $this->assertSame(['path' => 'settings._direction', 'operation' => 'replace', 'before_value' => 'column', 'after_value' => 'row'], array_intersect_key($edited['paths'][0], array_flip(['path', 'operation', 'before_value', 'after_value'])), 'before = this site, after = the export.');
        $this->assertSame(['path' => 'settings.api_key', 'operation' => 'remove', 'before_value' => '[redacted]', 'after_value' => '[redacted]'], array_intersect_key($edited['paths'][1], array_flip(['path', 'operation', 'before_value', 'after_value'])), 'Receipt redaction applies to file-mode values.');
        $removed_here = $result['items'][1];
        $this->assertNull($removed_here['matched_by']);
        $this->assertNull($removed_here['here_hash']);
        $this->assertSame('add', $removed_here['paths'][0]['operation']);
        $added_here = $result['items'][2];
        $this->assertNull($added_here['file_hash']);
        $this->assertSame(['id', 'name', 'settings'], array_column($added_here['paths'], 'path'), 'An object absent on one side diffs as its top-level keys.');
        $this->assertSame([['domain' => DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS, 'instance_uid' => $by_name['--flex-col']['instance_uid'], 'display_name' => '--flex-col', 'matched_by' => 'uid']], $result['matches']);

        $markdown = $result['markdown'];
        $this->assertStringContainsString('# Change report · ' . home_url() . ' → ' . home_url() . ' (file mode)', $markdown);
        $this->assertStringContainsString("## Differs (1)\n- **--flex-row** (" . DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS . ") · 2 paths\n  - settings._direction (replace): column → row\n  - settings.api_key (remove): [redacted] → [redacted]", $markdown);
        $this->assertStringContainsString('## Only in the export (1)', $markdown);
        $this->assertStringContainsString('## Only on this site (1)', $markdown);
        $this->assertStringNotContainsString('shh', $markdown);

        $without_values = DBVC_Connected_CLI_Inspector::compare_file(['file' => $out, 'values' => 'false']);
        $this->assertFalse($without_values['values_included']);
        $this->assertArrayNotHasKey('before_value', $without_values['items'][0]['paths'][0]);
        $this->assertStringContainsString('  - settings._direction (replace)' . "\n", $without_values['markdown']);

        // ---- matching by name when the UIDs differ (an unlinked clone) ------------------
        $foreign = $bundle;
        foreach ($foreign['objects'] as &$object) {
            $object['instance_uid'] = 'foreign-' . $object['instance_uid'];
        }
        unset($object);
        $by_name_result = (new FileComparison())->compare($foreign, ['values' => false]);
        $this->assertSame(['only_in_file' => 1, 'differs' => 1, 'only_here' => 1, 'same' => 1], $by_name_result['counts']);
        $this->assertSame('name', $by_name_result['items'][0]['matched_by']);
        $this->assertSame('foreign-' . $by_name['--flex-row']['instance_uid'], $by_name_result['items'][0]['file_instance_uid']);
        $this->assertSame($by_name['--flex-row']['instance_uid'], $by_name_result['items'][0]['instance_uid'], 'The row is named by this site\'s UID.');
        $this->assertSame('name', $by_name_result['matches'][0]['matched_by']);
        $this->assertStringContainsString('· matched by name', FileComparison::render_markdown($by_name_result));

        // ---- a domain this site does not know is reported, not compared -------------------
        $foreign['objects'][] = ['domain' => 'wp.nope', 'profile' => 'x', 'instance_uid' => 'n1', 'display_name' => 'Nope', 'semantic_hash' => 'h', 'body' => ['a' => 1]];
        $with_unknown = (new FileComparison())->compare($foreign);
        $this->assertSame('unavailable', $with_unknown['domains']['wp.nope']['status']);
        $this->assertCount(1, $with_unknown['notes']);
        $this->assertStringContainsString('## Notes', FileComparison::render_markdown($with_unknown));

        // ---- a --domains filter and bad input ---------------------------------------------
        $filtered = (new FileComparison())->compare($bundle, ['domains' => [DomainRegistry::DOMAIN_BRICKS_VARIABLE]]);
        $this->assertSame([DomainRegistry::DOMAIN_BRICKS_VARIABLE], array_keys($filtered['domains']));
        $this->assertSame('Nothing to compare.', $filtered['summary']);
        $this->assertSame('dbvc_connected_file_format', (new FileComparison())->compare(['format' => 'other'])->get_error_code());
        $this->assertSame('dbvc_connected_file_missing', DBVC_Connected_CLI_Inspector::compare_file([])->get_error_code());
        $this->assertSame('dbvc_connected_file_missing', DBVC_Connected_CLI_Inspector::compare_file(['file' => $out . '.nope'])->get_error_code());
        $garbage = $this->temp_file('garbage.json');
        file_put_contents($garbage, '{not json');
        $this->assertSame('dbvc_connected_file_format', DBVC_Connected_CLI_Inspector::compare_file(['file' => $garbage])->get_error_code());

        // ---- the connector gate still guards both halves ---------------------------------
        DBVC_Connected_Environments_Addon::save_settings([]);
        $this->assertSame('dbvc_connected_cli_not_ready', DBVC_Connected_CLI_Inspector::objects_export([])->get_error_code());
        $this->assertSame('dbvc_connected_cli_not_ready', DBVC_Connected_CLI_Inspector::compare_file(['file' => $out])->get_error_code());
    }

    /**
     * @param array<int, string> $names
     * @return array<int, string>
     */
    private function sorted(array $names): array
    {
        sort($names);

        return $names;
    }

    private function temp_file(string $name): string
    {
        $path = get_temp_dir() . 'dbvc-cr5-' . wp_generate_password(6, false) . '-' . $name;
        $this->temp_files[] = $path;

        return $path;
    }

    private function run_worker(): array
    {
        $runtime = DBVC_Connected_Environments_Addon::runtime();
        $this->assertNotNull($runtime);

        return $runtime->worker()->run(['context' => 'test', 'budget_seconds' => 30]);
    }

    /**
     * Shapes mirror live Bricks storage: sequential list, `id`, `name`, `settings`, optional `cat`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function classes_fixture(): array
    {
        return [
            ['id' => 'aaa111', 'name' => '--flex-col', 'settings' => ['_direction' => 'column'], 'cat' => false],
            ['id' => 'bbb222', 'name' => '--flex-row', 'settings' => ['_direction' => 'row', '_display' => 'flex']],
            ['id' => 'ccc333', 'name' => '--bg-gradient-light', 'settings' => ['_gradient' => ['applyTo' => 'overlay', 'colors' => [['id' => 'vypvsh', 'stop' => '0'], ['id' => 'wjtzex', 'stop' => '100']]]], 'cat' => 'rgxlvq'],
        ];
    }
}
