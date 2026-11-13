<?php

use Dbvc\VisualEditor\Performance\Bricks\ProviderFingerprint;
use PHPUnit\Framework\TestCase;

/**
 * R5.later-perf-a/b — per-Bricks-version pin proof (step 3a of the Vertical
 * bricks-acf-tag-cost plan).
 *
 * Each pinned Bricks version has a fixture (tests/fixtures/bricks-provider-acf/
 * bricks-<version>.php) holding the pinned Bricks methods verbatim, generated
 * by extract.php from the installed theme. The parity harness loads one
 * fixture per PHP process (Bricks class names are fixed) without WordPress and
 * reports: the fingerprints, a textual comparison of the vendored
 * register_tag() copy with the Bricks original, a tag-by-tag comparison of
 * IndexedAcfProvider with the stock provider over a hand-written schema that
 * reaches every register_tag() branch plus seeded random schemas, and the
 * shim's gate / verify() / arm / swap path end to end.
 *
 * Set DBVC_BRICKS_THEME_DIRS (PATH_SEPARATOR-separated `themes/bricks` dirs)
 * to also run the parity against real installed themes (read-only).
 */
class VisualEditorBricksAcfTagIndexParityTest extends TestCase
{
    /**
     * Fixture file => expected pins. 'versions' lists every Bricks release
     * confirmed (from the installed theme) to hash to this fingerprint.
     */
    private const FIXTURES = [
        'bricks-2.3.8.php' => [
            'perf_a' => '07662227df5bdbd7219e8e8613eb4834aec30902',
            'perf_b' => '0ca8ed62f2cbc8615347573f3709025e95b7e76d',
            'variant' => 'register_tag_bricks_2_3_8',
            'versions' => ['2.3.8', '2.3.9'],
            'clones' => false,
            'loop_outer' => false,
            'index_lines' => 2,
        ],
        'bricks-2.3.13.php' => [
            'perf_a' => '22fd17e9486aed26d2054a9011ab8668e4170631',
            'perf_b' => '0ca8ed62f2cbc8615347573f3709025e95b7e76d',
            'variant' => 'register_tag_bricks_2_3_13',
            'versions' => ['2.3.13'],
            'clones' => true,
            'loop_outer' => false,
            'index_lines' => 2,
        ],
        'bricks-2.4.5.php' => [
            'perf_a' => '463ec9d10206cf77bf4a0c66a0af5370974f346c',
            'perf_b' => '0ca8ed62f2cbc8615347573f3709025e95b7e76d',
            'variant' => 'register_tag_bricks_2_4_5',
            'versions' => ['2.4.5'],
            'clones' => true,
            'loop_outer' => true,
            'index_lines' => 3,
        ],
    ];

    /**
     * @var array<string, array<string, mixed>>
     */
    private static $runs = [];

    /**
     * @var string[]
     */
    private $temp_files = [];

    protected function tearDown(): void
    {
        foreach ($this->temp_files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private static function fixtureDir()
    {
        return dirname(__DIR__) . '/fixtures/bricks-provider-acf';
    }

    /**
     * @return array<string, mixed>
     */
    private function harness($bricks, $adopt = null, $mode = '')
    {
        $cache_key = $bricks . '|' . (string) $adopt . '|' . $mode;
        if (isset(self::$runs[$cache_key])) {
            return self::$runs[$cache_key];
        }

        $command = escapeshellarg(PHP_BINARY) . ' -d memory_limit=256M ' . escapeshellarg(self::fixtureDir() . '/parity-harness.php') . ' ' . escapeshellarg($bricks);
        if ($adopt !== null || $mode !== '') {
            $command .= ' ' . escapeshellarg((string) $adopt);
        }
        if ($mode !== '') {
            $command .= ' ' . escapeshellarg($mode);
        }

        $output = [];
        $code = 0;
        exec($command . ' 2>&1', $output, $code);
        $json = implode("\n", $output);
        $this->assertSame(0, $code, "harness failed for {$bricks}:\n" . $json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, "harness output was not JSON for {$bricks}:\n" . $json);

        return self::$runs[$cache_key] = $decoded;
    }

    /**
     * Copy a fixture with one source edit applied.
     */
    private function mutatedFixture($fixture, $search, $replace)
    {
        $source = (string) file_get_contents(self::fixtureDir() . '/' . $fixture);
        $this->assertSame(1, substr_count($source, $search), "mutation anchor must be unique in {$fixture}: {$search}");
        $file = tempnam(sys_get_temp_dir(), 'dbvc-bricks-fixture-') . '.php';
        file_put_contents($file, str_replace($search, $replace, $source));
        $this->temp_files[] = $file;

        return $file;
    }

    // ---------------------------------------------------------------------
    // Pin bookkeeping
    // ---------------------------------------------------------------------

    public function test_every_pin_has_a_fixture_and_a_vendored_variant_and_vice_versa(): void
    {
        $fixture_pins = array_column(self::FIXTURES, 'perf_a');
        $this->assertEqualsCanonicalizing(array_keys(ProviderFingerprint::KNOWN), $fixture_pins, 'each perf-a pin needs exactly one parity fixture');
        $this->assertEqualsCanonicalizing(array_keys(ProviderFingerprint::KNOWN), array_keys(ProviderFingerprint::REGISTER_TAG_VARIANTS), 'each perf-a pin selects a vendored register_tag()');

        foreach (self::FIXTURES as $fixture => $pin) {
            $this->assertFileExists(self::fixtureDir() . '/' . $fixture);
            $this->assertSame($pin['variant'], ProviderFingerprint::REGISTER_TAG_VARIANTS[$pin['perf_a']]);
            $this->assertArrayHasKey($pin['perf_b'], ProviderFingerprint::KNOWN_FIELDS_CACHE);
            foreach ($pin['versions'] as $version) {
                $this->assertStringContainsString($version, ProviderFingerprint::label($pin['perf_a']));
            }
        }

        $this->assertNull(ProviderFingerprint::registerTagVariant(null));
        $this->assertNull(ProviderFingerprint::registerTagVariant('0000000000000000000000000000000000000000'));
    }

    // ---------------------------------------------------------------------
    // Per pinned version
    // ---------------------------------------------------------------------

    public function test_fixtures_hash_to_the_pinned_fingerprints(): void
    {
        foreach (self::FIXTURES as $fixture => $pin) {
            $run = $this->harness(self::fixtureDir() . '/' . $fixture);
            $this->assertTrue($run['fixtureMode']);
            $this->assertSame($pin['perf_a'], $run['fingerprint'], "{$fixture}: perf-a fingerprint");
            $this->assertSame($pin['perf_b'], $run['fieldsCacheFingerprint'], "{$fixture}: perf-b fingerprint");
            $this->assertTrue($run['known']);
            $this->assertTrue($run['fieldsCacheKnown']);
            $this->assertSame($pin['variant'], $run['variant']);
            $this->assertTrue($run['registryAccessible']);
        }
    }

    public function test_vendored_register_tag_is_the_bricks_original_plus_only_the_documented_edits(): void
    {
        foreach (self::FIXTURES as $fixture => $pin) {
            $run = $this->harness(self::fixtureDir() . '/' . $fixture);
            $this->assertSame(['signature' => 1, 'contexts' => 1, 'layouts' => 1, 'indexLines' => $pin['index_lines']], $run['source']['edits'], "{$fixture}: exactly the documented edits are present");
            $this->assertNotEmpty($run['source']['stock']);
            $this->assertSame($run['source']['stock'], $run['source']['vendored'], "{$fixture}: {$pin['variant']} must equal Bricks' register_tag() once the documented edits are undone");
        }
    }

    public function test_indexed_provider_matches_stock_bricks_tag_by_tag(): void
    {
        foreach (self::FIXTURES as $fixture => $pin) {
            $run = $this->harness(self::fixtureDir() . '/' . $fixture);
            $this->assertSame([], $run['warnings'], "{$fixture}: the schemas raise no PHP warnings in either provider");

            foreach ($run['parity'] as $schema => $result) {
                $this->assertSame(0, $result['differingCount'], "{$fixture} / {$schema}: differing tags " . implode(', ', $result['differing']));
                $this->assertTrue($result['identical'], "{$fixture} / {$schema}: tags and loop_tags strictly identical");
                $this->assertTrue($result['orderIdentical'], "{$fixture} / {$schema}: same order");
                $this->assertSame(0, $result['stockWarnings']);
                $this->assertGreaterThan(50, $result['tags']);
                $this->assertGreaterThan(0, $result['loopTags']);
                $this->assertGreaterThan(0, $result['nestedGroupTags'], "{$fixture} / {$schema}: the indexed nested-group lookup is exercised");
                $this->assertGreaterThan(0, $result['duplicateTags'], "{$fixture} / {$schema}: overwrites/duplicates are exercised");
            }

            // Group-display clone sub-fields are tags from 2.3.13 on, not before.
            $this->assertSame($pin['clones'] ? 5 : 0, $run['parity']['handwritten']['cloneLabelledTags'], "{$fixture}: clone handling");
            // Outer loop fields are mirrored into tags (is_loop_outer) from 2.4.5 on.
            if ($pin['loop_outer']) {
                $this->assertGreaterThan(0, $run['parity']['handwritten']['loopOuterTags'], "{$fixture}: is_loop_outer tags exercised");
            } else {
                $this->assertSame(0, $run['parity']['handwritten']['loopOuterTags']);
            }
        }
    }

    public function test_verify_arm_and_swap_end_to_end_on_each_pinned_version(): void
    {
        foreach (self::FIXTURES as $fixture => $pin) {
            $run = $this->harness(self::fixtureDir() . '/' . $fixture);

            $this->assertSame('not_verified', $run['gateBeforeVerify'], "{$fixture}: known but unverified never arms");
            $this->assertSame('not_verified', $run['armStateBeforeVerify']);
            $this->assertFalse($run['swapHookedBeforeVerify']);

            $this->assertTrue($run['verify']['ok'], "{$fixture}: verify() " . $run['verify']['reason']);
            $this->assertSame('identical', $run['verify']['reason']);
            $this->assertSame(0, $run['verify']['differing']);
            $this->assertGreaterThan(200, $run['verify']['tags']);
            $this->assertSame($pin['perf_a'], $run['verifiedOption']);
            $this->assertSame('ok', $run['gateAfterVerify']);

            $this->assertSame('armed', $run['armState']);
            $this->assertSame(10000, $run['swapHooked']);
            $this->assertSame('active', $run['swapState']);
            $this->assertTrue($run['swappedIsIndexed']);
            $this->assertSame([true, true, true], $run['filtersRepointed'], "{$fixture}: the three Base::__construct filters now point at the indexed instance");
            $this->assertTrue($run['swappedIdentical'], "{$fixture}: the swapped-in provider builds the stock registry");

            $this->assertSame('not_verified', $run['gateAfterFailedMarker']);
        }
    }

    // ---------------------------------------------------------------------
    // The comparison would catch a wrong vendored copy
    // ---------------------------------------------------------------------

    public function test_running_another_versions_copy_is_caught_textually_and_tag_by_tag(): void
    {
        $fixtures = array_keys(self::FIXTURES);
        $this->assertGreaterThanOrEqual(2, count($fixtures));

        foreach ($fixtures as $fixture) {
            foreach ($fixtures as $other) {
                if ($other === $fixture) {
                    continue;
                }

                $run = $this->harness(self::fixtureDir() . '/' . $fixture, self::FIXTURES[$other]['perf_a']);
                $this->assertNotSame($run['source']['stock'], $run['source']['vendored'], "{$fixture} with {$other}'s copy: textual check fails");
                $this->assertFalse($run['parity']['handwritten']['identical'], "{$fixture} with {$other}'s copy: tag comparison fails");
                $this->assertGreaterThan(0, $run['parity']['handwritten']['differingCount']);
            }
        }
    }

    public function test_skipping_is_loop_outer_tags_is_required_where_bricks_skips_them(): void
    {
        foreach (self::FIXTURES as $fixture => $pin) {
            if (! $pin['loop_outer']) {
                continue;
            }

            $run = $this->harness(self::fixtureDir() . '/' . $fixture, null, 'noskip');
            $this->assertFalse($run['parity']['handwritten']['identical'], "{$fixture}: an indexed lookup that used outer loop tags as group ancestors would rename tags");
            $this->assertGreaterThan(0, $run['parity']['handwritten']['differingCount']);
        }
    }

    // ---------------------------------------------------------------------
    // The gate refuses unknown or modified Bricks
    // ---------------------------------------------------------------------

    /**
     * One edit inside each pinned method of each fixture.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: bool}> fixture, search, replace, perf-b affected
     */
    private static function mutations()
    {
        $cases = [];
        foreach (array_keys(self::FIXTURES) as $fixture) {
            $cases += [
                "{$fixture} register_tag" => [$fixture, "'group'    => 'ACF',", "'group'    => 'ACF Fields',", false],
                "{$fixture} get_nested_parent_group_field_data" => [$fixture, "\$data['parent_group_names'][] = \$parent_field['name'];", "\$data['parent_group_names'][] = (string) \$parent_field['name'];", false],
                "{$fixture} get_fields_by_context" => [$fixture, "'color_picker'     => [ self::CONTEXT_TEXT ],", "'color_picker'     => [ self::CONTEXT_TEXT, self::CONTEXT_LINK ],", false],
                "{$fixture} get_flexible_content_parent_layout_data" => [$fixture, '// Fast path: layouts already keyed by layout key.', '// Fast path: keyed layouts.', false],
                "{$fixture} Base::__construct" => [$fixture, "add_filter( 'bricks/query/run', [ \$this, 'set_loop_query' ], 10, 2 );", "add_filter( 'bricks/query/run', [ \$this, 'set_loop_query' ], 11, 2 );", false],
                "{$fixture} Providers::register" => [$fixture, "add_action( \$register_hook, [ \$instance, 'register_tags' ], 10001 );", "add_action( \$register_hook, [ \$instance, 'register_tags' ], 10002 );", false],
                "{$fixture} required_hooks (perf-b only)" => [$fixture, "add_action( 'save_post_acf-field-group', [ __CLASS__, 'flush_cache' ] );", "add_action( 'save_post_acf-field-group', [ __CLASS__, 'flush_cache' ], 11 );", true],
            ];
        }

        return $cases;
    }

    public function test_gate_refuses_bricks_whose_pinned_code_was_modified(): void
    {
        foreach (self::mutations() as $label => [$fixture, $search, $replace, $perf_b_only]) {
            $run = $this->harness($this->mutatedFixture($fixture, $search, $replace));
            $pin = self::FIXTURES[$fixture];

            if ($perf_b_only) {
                $this->assertSame($pin['perf_a'], $run['fingerprint'], "{$label}: perf-a does not hash this method");
                $this->assertFalse($run['fieldsCacheKnown'], "{$label}: perf-b refuses");
                $this->assertNotSame($pin['perf_b'], $run['fieldsCacheFingerprint']);
                continue;
            }

            $this->assertNotSame($pin['perf_a'], $run['fingerprint'], "{$label}: fingerprint moves");
            $this->assertFalse($run['known'], "{$label}: unknown");
            $this->assertNull($run['variant']);
            $this->assertSame('unknown', $run['label']);
            $this->assertSame('fingerprint_unknown', $run['gateBeforeVerify']);
            $this->assertSame('fingerprint_unknown', $run['armStateBeforeVerify']);
            $this->assertFalse($run['swapHookedBeforeVerify'], "{$label}: no swap is hooked");
            $this->assertFalse($run['verify']['ok']);
            $this->assertSame('fingerprint_unknown', $run['verify']['reason']);
            $this->assertSame('', $run['verifiedOption'], "{$label}: a refused verify persists nothing");
            $this->assertSame('refused', $run['adoptUnknown'], "{$label}: IndexedAcfProvider refuses an unknown fingerprint");
        }
    }

    public function test_formatting_only_changes_keep_the_pin(): void
    {
        foreach (self::FIXTURES as $fixture => $pin) {
            $run = $this->harness($this->mutatedFixture($fixture, "\t\t\$type = \$field['type'];\n", "\n\n            \$type   =   \$field['type'];    \r\n"));
            $this->assertSame($pin['perf_a'], $run['fingerprint'], "{$fixture}: whitespace-only edits are normalised away");
        }
    }

    // ---------------------------------------------------------------------
    // Optional: real installed themes (local only)
    // ---------------------------------------------------------------------

    public function test_real_installed_bricks_themes_when_provided(): void
    {
        $dirs = array_filter(explode(PATH_SEPARATOR, (string) getenv('DBVC_BRICKS_THEME_DIRS')));
        if ($dirs === []) {
            $this->markTestSkipped('Set DBVC_BRICKS_THEME_DIRS to run the parity against installed Bricks themes.');
        }

        foreach ($dirs as $dir) {
            $run = $this->harness($dir);
            $this->assertFalse($run['fixtureMode']);
            $this->assertTrue($run['known'], "{$dir}: installed Bricks is pinned ({$run['fingerprint']})");
            $this->assertTrue($run['fieldsCacheKnown'], "{$dir}: perf-b pin ({$run['fieldsCacheFingerprint']})");
            $this->assertSame($run['source']['stock'], $run['source']['vendored'], "{$dir}: vendored copy matches");
            foreach ($run['parity'] as $schema => $result) {
                $this->assertTrue($result['identical'], "{$dir} / {$schema}: " . implode(', ', $result['differing']));
            }
        }
    }
}
