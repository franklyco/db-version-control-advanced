<?php

use Dbvc\VisualEditor\Performance\Bricks\AcfTagIndex;
use Dbvc\VisualEditor\Performance\Bricks\AcfTagIndexShim;
use Dbvc\VisualEditor\Performance\Bricks\ProviderFingerprint;

/**
 * R5.later-perf-a — Bricks ACF dynamic-data tag-index shim (E-160, D-082).
 *
 * The test environment has no Bricks theme, so these tests cover everything
 * that does not need it: the index algorithm against the reference scan, the
 * fingerprint normalisation, the gate's refusal paths, the settings wiring and
 * the "writes nothing at runtime" invariant. The live equivalence proof runs on
 * a Bricks site via AcfTagIndexShim::verify() (see the release note).
 */
class VisualEditorBricksAcfTagIndexTest extends WP_UnitTestCase
{
    /**
     * @var array<string, mixed>
     */
    private $previous_options = [];

    public function set_up(): void
    {
        parent::set_up();

        foreach ([AcfTagIndexShim::OPTION_ENABLED, AcfTagIndexShim::OPTION_VERIFIED, DBVC_Visual_Editor_Addon::OPTION_SETTINGS_VERSION] as $option) {
            $this->previous_options[$option] = get_option($option, '__dbvc_missing_option__');
        }

        AcfTagIndexShim::reset();
    }

    public function tear_down(): void
    {
        foreach ($this->previous_options as $option => $value) {
            if ($value === '__dbvc_missing_option__') {
                delete_option($option);
            } else {
                update_option($option, $value);
            }
        }

        AcfTagIndexShim::reset();
        remove_all_filters(AcfTagIndexShim::FILTER_ENABLED);
        parent::tear_down();
    }

    // ---------------------------------------------------------------------
    // Index algorithm
    // ---------------------------------------------------------------------

    public function test_index_matches_the_reference_scan_on_a_randomised_registration_replay(): void
    {
        mt_srand(20260917);
        $lookups = 0;
        $mismatches = [];

        for ($round = 0; $round < 25; $round++) {
            $index = new AcfTagIndex();
            $tags = [];
            $keys = [];
            for ($k = 0; $k < 40; $k++) {
                $keys[] = 'field_' . dechex(mt_rand(0x1000, 0xffff)) . '_' . $k;
            }
            $names = [];
            for ($n = 0; $n < 30; $n++) {
                $names[] = 'acf_name_' . mt_rand(0, 17); // deliberate duplicates → overwrites
            }

            for ($step = 0; $step < 120; $step++) {
                $name = $names[mt_rand(0, count($names) - 1)];
                $sub_count = mt_rand(0, 4);
                $sub_fields = [];
                for ($i = 0; $i < $sub_count; $i++) {
                    $sub_fields[] = ['key' => $keys[mt_rand(0, count($keys) - 1)], 'name' => 'sub' . $i];
                }
                $tag = ['name' => '{' . $name . '}', 'field' => ['key' => 'field_parent_' . $step, 'sub_fields' => $sub_fields]];

                // Replay exactly what the vendored register_tag() does: assign, then index.
                $tags[$name] = $tag;
                $index->indexTag($name, $tags[$name]);

                // Every lookup must agree with the stock scan at every point in time.
                foreach ($keys as $key) {
                    $lookups++;
                    if (AcfTagIndex::referenceLookup($key, $tags) !== $index->lookup($key, $tags)) {
                        $mismatches[] = "round {$round} step {$step} key {$key}";
                    }
                }
            }
        }

        $this->assertSame(120000, $lookups);
        $this->assertSame([], $mismatches);
    }

    public function test_index_honours_array_order_and_overwrites(): void
    {
        $index = new AcfTagIndex();
        $tags = [];

        $tags['acf_a'] = ['field' => ['sub_fields' => [['key' => 'k1']]]];
        $index->indexTag('acf_a', $tags['acf_a']);
        $tags['acf_b'] = ['field' => ['sub_fields' => [['key' => 'k1'], ['key' => 'k2']]]];
        $index->indexTag('acf_b', $tags['acf_b']);

        $this->assertSame('acf_a', $index->lookup('k1', $tags), 'first in array order wins');
        $this->assertSame('acf_b', $index->lookup('k2', $tags));
        $this->assertNull($index->lookup('k3', $tags));

        // Overwrite acf_a with a field that no longer carries k1 — the scan now finds acf_b.
        $tags['acf_a'] = ['field' => ['sub_fields' => [['key' => 'k9']]]];
        $index->indexTag('acf_a', $tags['acf_a']);
        $this->assertSame('acf_b', $index->lookup('k1', $tags));
        $this->assertSame('acf_a', $index->lookup('k9', $tags), 'overwritten name keeps its original position');

        // A later duplicate that re-adds k1 under acf_a wins again (position 0 < position 1).
        $tags['acf_a'] = ['field' => ['sub_fields' => [['key' => 'k1']]]];
        $index->indexTag('acf_a', $tags['acf_a']);
        $this->assertSame('acf_a', $index->lookup('k1', $tags));

        // Tags without sub_fields, or with malformed entries, never match.
        $tags['acf_c'] = ['field' => ['type' => 'text']];
        $index->indexTag('acf_c', $tags['acf_c']);
        $tags['acf_d'] = ['field' => ['sub_fields' => ['not-an-array', ['name' => 'no-key']]]];
        $index->indexTag('acf_d', $tags['acf_d']);
        $this->assertSame(AcfTagIndex::referenceLookup('k1', $tags), $index->lookup('k1', $tags));
    }

    // ---------------------------------------------------------------------
    // Fingerprint
    // ---------------------------------------------------------------------

    public function test_fingerprint_normalisation_ignores_formatting_only_changes(): void
    {
        $a = "\tpublic function register_tag( \$field ) {\n\t\t\$x = 1;\r\n\n\t}\n";
        $b = "public function register_tag(   \$field ) {\n    \$x   = 1;\n}";
        $this->assertSame(ProviderFingerprint::normalize($a), ProviderFingerprint::normalize($b));
        $this->assertNotSame(ProviderFingerprint::normalize($a), ProviderFingerprint::normalize("public function register_tag( \$field ) {\n \$x = 2;\n}"));
    }

    public function test_fingerprint_is_null_and_unknown_without_bricks(): void
    {
        $this->assertFalse(ProviderFingerprint::bricksLoaded());
        $this->assertNull(ProviderFingerprint::current());
        $this->assertFalse(ProviderFingerprint::isKnown(null));
        $this->assertFalse(ProviderFingerprint::isKnown('not-a-known-hash'));
        $this->assertSame('unknown', ProviderFingerprint::label(null));
        $this->assertNotEmpty(ProviderFingerprint::KNOWN, 'at least one pinned Bricks version');
        foreach (ProviderFingerprint::KNOWN as $hash => $label) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $hash);
            $this->assertStringStartsWith('Bricks ', $label);
        }
        $this->assertFalse(ProviderFingerprint::providersRegistryAccessible());
        $this->assertNull(ProviderFingerprint::methodSource('Definitely\\Missing\\Klass', 'nope'));
    }

    // ---------------------------------------------------------------------
    // Gate, defaults, runtime invariants
    // ---------------------------------------------------------------------

    public function test_defaults_are_off_and_settings_version_bumped(): void
    {
        delete_option(AcfTagIndexShim::OPTION_ENABLED);
        delete_option(AcfTagIndexShim::OPTION_VERIFIED);
        update_option(DBVC_Visual_Editor_Addon::OPTION_SETTINGS_VERSION, '7');

        DBVC_Visual_Editor_Addon::ensure_defaults();

        $this->assertSame('0', get_option(AcfTagIndexShim::OPTION_ENABLED));
        $this->assertSame('', get_option(AcfTagIndexShim::OPTION_VERIFIED));
        $this->assertSame('9', get_option(DBVC_Visual_Editor_Addon::OPTION_SETTINGS_VERSION), 'perf-a 7 → 8, perf-b 8 → 9');
        $this->assertFalse(AcfTagIndexShim::isEnabled());
        $this->assertSame(AcfTagIndexShim::OPTION_ENABLED, DBVC_Visual_Editor_Addon::OPTION_BRICKS_ACF_TAG_INDEX_ENABLED);
    }

    public function test_register_does_nothing_when_disabled_and_arms_nothing_without_bricks(): void
    {
        update_option(AcfTagIndexShim::OPTION_ENABLED, '0');
        AcfTagIndexShim::register();
        $this->assertSame(0, has_action('after_setup_theme', [AcfTagIndexShim::class, 'arm']), 'one cheap hook is always registered; the switch is read in arm()');
        AcfTagIndexShim::arm();
        $this->assertSame('disabled', AcfTagIndexShim::state());
        $this->assertFalse(has_action('init', [AcfTagIndexShim::class, 'swap']));

        // Enabled, but no Bricks in this environment → the gate refuses before any hook is added.
        AcfTagIndexShim::reset();
        update_option(AcfTagIndexShim::OPTION_ENABLED, '1');
        AcfTagIndexShim::register();
        AcfTagIndexShim::arm();
        $this->assertSame('bricks_not_loaded', AcfTagIndexShim::state());
        $this->assertSame('bricks_not_loaded', AcfTagIndexShim::gate());
        $this->assertFalse(has_action('init', [AcfTagIndexShim::class, 'swap']));

        // swap() is defensive even if called directly.
        AcfTagIndexShim::swap();
        $this->assertStringStartsWith('error:', AcfTagIndexShim::state());
    }

    public function test_filter_can_veto_or_force_the_switch(): void
    {
        update_option(AcfTagIndexShim::OPTION_ENABLED, '1');
        add_filter(AcfTagIndexShim::FILTER_ENABLED, '__return_false');
        $this->assertFalse(AcfTagIndexShim::isEnabled());
        remove_filter(AcfTagIndexShim::FILTER_ENABLED, '__return_false');

        update_option(AcfTagIndexShim::OPTION_ENABLED, '0');
        add_filter(AcfTagIndexShim::FILTER_ENABLED, '__return_true');
        $this->assertTrue(AcfTagIndexShim::isEnabled());
    }

    public function test_verify_refuses_without_bricks_and_writes_nothing_unless_asked(): void
    {
        update_option(AcfTagIndexShim::OPTION_VERIFIED, 'previous');

        $result = AcfTagIndexShim::verify(false);
        $this->assertFalse($result['ok']);
        $this->assertSame('bricks_not_loaded', $result['reason']);
        $this->assertSame('previous', get_option(AcfTagIndexShim::OPTION_VERIFIED), 'a refused verify never touches the marker');

        $result = AcfTagIndexShim::verify(true);
        $this->assertFalse($result['ok']);
        $this->assertSame('previous', get_option(AcfTagIndexShim::OPTION_VERIFIED), 'refusal before the comparison persists nothing even with persist=true');

        $status = AcfTagIndexShim::status();
        $this->assertSame('bricks_not_loaded', $status['gate']);
        $this->assertSame('unknown', $status['bricks']);
        $this->assertNull($status['fingerprint']);
    }

    public function test_settings_page_wiring_and_save_path(): void
    {
        $groups = DBVC_Visual_Editor_Addon::get_settings_groups();
        $this->assertArrayHasKey('performance_bricks', $groups);
        $this->assertContains(AcfTagIndexShim::OPTION_ENABLED, $groups['performance_bricks']['fields']);

        $meta = DBVC_Visual_Editor_Addon::get_field_meta();
        $this->assertSame('checkbox', $meta[AcfTagIndexShim::OPTION_ENABLED]['input']);
        $this->assertStringContainsString('site-wide', $meta[AcfTagIndexShim::OPTION_ENABLED]['help']);
        $this->assertStringContainsString('gate: bricks_not_loaded', $meta[AcfTagIndexShim::OPTION_ENABLED]['help']);

        $this->assertArrayHasKey(AcfTagIndexShim::OPTION_ENABLED, DBVC_Visual_Editor_Addon::get_all_settings());

        // Saving with the box unticked stores '0' and runs no verification.
        update_option(AcfTagIndexShim::OPTION_VERIFIED, '');
        $result = DBVC_Visual_Editor_Addon::save_settings([]);
        $this->assertSame('0', $result['values'][AcfTagIndexShim::OPTION_ENABLED]);
        $this->assertSame([], $result['errors']);
        $this->assertSame('', get_option(AcfTagIndexShim::OPTION_VERIFIED));

        // Saving with the box ticked stores '1', attempts verification, and reports the refusal.
        $result = DBVC_Visual_Editor_Addon::save_settings([AcfTagIndexShim::OPTION_ENABLED => 'on']);
        $this->assertSame('1', $result['values'][AcfTagIndexShim::OPTION_ENABLED]);
        $this->assertSame('1', get_option(AcfTagIndexShim::OPTION_ENABLED));
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('bricks_not_loaded', $result['errors'][0]);
        $this->assertSame('', get_option(AcfTagIndexShim::OPTION_VERIFIED), 'no fingerprint recorded when Bricks is absent');
    }
}
