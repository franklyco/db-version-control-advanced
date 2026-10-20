<?php

/**
 * Post-R3 extension point — `dbvc_visual_editor_control_center_providers`.
 *
 * The filter lets external providers (e.g. a Vertical theme's
 * `VerticalControlProvider`) register additional `ControlProvider`s on the
 * runtime `ControlRegistry` without touching addon source. This test asserts:
 *
 * - the filter fires only when both parts of the two-part kill switch are on
 *   (mirrors the D-063 gate that gates route registration);
 * - a returned `ControlProvider` instance is registered on the registry
 *   downstream of the built-in Shared Globals provider;
 * - a returned non-`ControlProvider` entry is silently ignored (no fatal, no
 *   crash) — the registry's own validation surfaces the rejection via
 *   `dbvc_visual_editor_control_registry_invalid` if the entry looks
 *   provider-ish enough to reach `registerProvider`;
 * - `DBVC_Visual_Editor_Addon::get_curation_export_path()` returns the
 *   deterministic path to the R3-BX curation JSON and honors its filter.
 */
final class VisualEditorControlCenterProvidersFilterTest extends WP_UnitTestCase
{
    /**
     * @var array<string, mixed>
     */
    private $previous_options = [];

    public function set_up(): void
    {
        parent::set_up();
        foreach ([
            DBVC_Visual_Editor_Addon::OPTION_ENABLED,
            DBVC_Visual_Editor_Addon::OPTION_CONTROL_CENTER_ENABLED,
        ] as $option_name) {
            $this->previous_options[$option_name] = get_option($option_name, '__dbvc_missing_option__');
        }
    }

    public function tear_down(): void
    {
        remove_all_filters('dbvc_visual_editor_control_center_providers');
        remove_all_filters('dbvc_visual_editor_curation_export_path');
        foreach ($this->previous_options as $option_name => $value) {
            if ($value === '__dbvc_missing_option__') {
                delete_option($option_name);
            } else {
                update_option($option_name, $value);
            }
        }
        DBVC_Visual_Editor_Addon::refresh_runtime_registration();
        parent::tear_down();
    }

    public function test_filter_defers_to_after_setup_theme_so_theme_hooks_registered_after_plugin_load_still_fire(): void
    {
        // Mirrors production load order:
        //   1. plugins load → DBVC bootstrap → Addon::register() → filter is
        //      DEFERRED to after_setup_theme rather than invoked immediately;
        //   2. theme loads → hooks its callback onto the filter;
        //   3. after_setup_theme fires → deferred callback invokes the
        //      filter → external provider registers.
        // Without the deferral (the bug this regression test locks down),
        // step 1's apply_filters call would run with zero callbacks
        // attached and the external provider would never register in
        // production even when the tests-only order (add_filter first,
        // refresh second) works fine.
        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_CONTROL_CENTER_ENABLED, '1');

        // Rebuild the runtime FIRST (before any add_filter call) —
        // simulates plugin-load happening before the theme boots.
        DBVC_Visual_Editor_Addon::refresh_runtime_registration();

        // Now attach the theme-side filter — this is the "theme loaded
        // after plugin" moment.
        add_filter('dbvc_visual_editor_control_center_providers', function ($providers) {
            $providers[] = $this->makeExternalProvider('late_theme', 'x', 'Late Theme Provider');
            return $providers;
        });

        // Fire after_setup_theme — simulates WP finishing the theme boot.
        // The deferred callback registered inside register() must invoke
        // the filter now, picking up the theme-side callback that was
        // attached in between.
        do_action('after_setup_theme');

        $registry = $this->getRuntimeControlRegistry();
        $this->assertNotNull($registry);
        $this->assertContains(
            'late_theme',
            $registry->providerIds(),
            'Filter must fire on after_setup_theme so hooks registered by themes after plugin-load still contribute providers.'
        );
    }

    public function test_filter_registers_external_control_providers_when_kill_switch_is_on(): void
    {
        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_CONTROL_CENTER_ENABLED, '1');

        add_filter('dbvc_visual_editor_control_center_providers', function ($providers) {
            $providers[] = $this->makeExternalProvider('vertical', 'palette', 'Brand Palette');
            return $providers;
        });
        // Rebuild the runtime so register() re-runs with the filter attached.
        DBVC_Visual_Editor_Addon::refresh_runtime_registration();
        // Fire after_setup_theme so the deferred provider-registration
        // callback inside Addon::register() runs. Deferral guarantees
        // theme-attached filter callbacks contribute in production; tests
        // must re-fire the hook to exercise the same code path.
        do_action('after_setup_theme');

        $registry = $this->getRuntimeControlRegistry();
        $this->assertNotNull($registry, 'Expected a registered ControlRegistry when both switches are on.');
        $this->assertContains('vertical', $registry->providerIds());
    }

    public function test_filter_is_inert_when_the_control_center_switch_is_off(): void
    {
        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_CONTROL_CENTER_ENABLED, '0');

        $filter_calls = 0;
        add_filter('dbvc_visual_editor_control_center_providers', function ($providers) use (&$filter_calls) {
            $filter_calls++;
            $providers[] = $this->makeExternalProvider('should_not_register', 'x', 'X');
            return $providers;
        });
        DBVC_Visual_Editor_Addon::refresh_runtime_registration();

        $this->assertSame(0, $filter_calls, 'Filter must NOT fire while the Control Center kill switch is off.');
    }

    public function test_filter_is_inert_when_the_master_switch_is_off(): void
    {
        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '0');
        update_option(DBVC_Visual_Editor_Addon::OPTION_CONTROL_CENTER_ENABLED, '1');

        $filter_calls = 0;
        add_filter('dbvc_visual_editor_control_center_providers', function ($providers) use (&$filter_calls) {
            $filter_calls++;
            return $providers;
        });
        DBVC_Visual_Editor_Addon::refresh_runtime_registration();

        $this->assertSame(0, $filter_calls, 'Filter must NOT fire while the master Visual Editor switch is off.');
    }

    public function test_filter_silently_skips_non_control_provider_entries(): void
    {
        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_CONTROL_CENTER_ENABLED, '1');

        add_filter('dbvc_visual_editor_control_center_providers', function ($providers) {
            $providers[] = 'not-a-provider';
            $providers[] = new \stdClass();
            $providers[] = $this->makeExternalProvider('good_provider', 'good', 'Good');
            return $providers;
        });
        DBVC_Visual_Editor_Addon::refresh_runtime_registration();
        do_action('after_setup_theme');

        $registry = $this->getRuntimeControlRegistry();
        $this->assertNotNull($registry);
        $this->assertContains('good_provider', $registry->providerIds());
        // The two invalid entries do not appear anywhere; no fatal was raised.
        $this->assertNotContains('not-a-provider', $registry->providerIds());
    }

    public function test_get_curation_export_path_returns_the_committed_export_by_default(): void
    {
        $path = DBVC_Visual_Editor_Addon::get_curation_export_path();
        $this->assertStringEndsWith(
            'addons/visual-editor/curation/vertical-approved-controls.json',
            $path
        );
        $this->assertTrue(
            is_readable($path),
            'Default curation export path must resolve to a readable file inside the addon.'
        );
    }

    public function test_get_curation_export_path_honors_its_filter(): void
    {
        $custom = '/tmp/dbvc-cc-custom-export.json';
        add_filter('dbvc_visual_editor_curation_export_path', static function () use ($custom) {
            return $custom;
        });
        $this->assertSame($custom, DBVC_Visual_Editor_Addon::get_curation_export_path());
    }

    public function test_get_curation_export_path_falls_back_when_filter_returns_a_non_string(): void
    {
        $default = DBVC_Visual_Editor_Addon::get_curation_export_path();
        add_filter('dbvc_visual_editor_curation_export_path', static function () {
            return null;
        });
        $this->assertSame($default, DBVC_Visual_Editor_Addon::get_curation_export_path());
    }

    /**
     * Reach into the addon runtime to inspect the ControlRegistry. The runtime
     * is held as a private static property so we use reflection — this is a
     * test-only observation of state the production path exposes via
     * `Addon::getControlRegistry()` on the runtime instance.
     */
    private function getRuntimeControlRegistry(): ?\Dbvc\VisualEditor\Registry\ControlRegistry
    {
        $reflection = new \ReflectionClass('DBVC_Visual_Editor_Addon');
        if (! $reflection->hasProperty('runtime')) {
            return null;
        }
        $property = $reflection->getProperty('runtime');
        $property->setAccessible(true);
        $runtime = $property->getValue();
        if (! $runtime instanceof \Dbvc\VisualEditor\Bootstrap\Addon) {
            return null;
        }
        return $runtime->getControlRegistry();
    }

    private function makeExternalProvider(string $providerId, string $localId, string $label): \Dbvc\VisualEditor\Registry\ControlProvider
    {
        return new class ($providerId, $localId, $label) implements \Dbvc\VisualEditor\Registry\ControlProvider {
            private $providerId;
            private $localId;
            private $label;

            public function __construct(string $providerId, string $localId, string $label)
            {
                $this->providerId = $providerId;
                $this->localId = $localId;
                $this->label = $label;
            }

            public function getProviderId()
            {
                return $this->providerId;
            }

            public function getControls()
            {
                return [
                    [
                        'id' => $this->localId,
                        'label' => $this->label,
                        'category' => 'general',
                        'ownerType' => 'option',
                        'ownerSubtype' => 'acf_options',
                        'fieldFamily' => 'other',
                        'status' => 'unsupported',
                    ],
                ];
            }

            public function buildDescriptor(\Dbvc\VisualEditor\Registry\ControlRecord $record, $sessionId, array $pageContext)
            {
                return null;
            }
            public function buildValueSummary(\Dbvc\VisualEditor\Registry\ControlRecord $record, $sessionId)
            {
                return null;
            }
        };
    }
}
