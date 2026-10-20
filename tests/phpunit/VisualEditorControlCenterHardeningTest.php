<?php

/**
 * R3-D — Brand Control Center production hardening.
 *
 * Complements VisualEditorControlCenterRoutesTest (behavior-level guards for
 * the two REST controllers R3-C-1 shipped) with the invariants R3-D exists to
 * lock down for ship: the two REST routes must NOT be registered when the
 * two-part kill switch is off in either half, and the drawer's frontend
 * assets must NOT enqueue inside a Bricks Builder request. Together with the
 * jsdom coverage from R3-C-2 (14 tests) and the descriptor-shape tests from
 * R3-C-1 (10 tests), this is the ship-readiness safety net for R3.
 */
final class VisualEditorControlCenterHardeningTest extends WP_UnitTestCase
{
    /**
     * @var array<string, mixed>
     */
    private $previous_options = [];

    /**
     * @var int
     */
    private $administrator_id = 0;

    public function set_up(): void
    {
        parent::set_up();
        foreach ([
            DBVC_Visual_Editor_Addon::OPTION_ENABLED,
            DBVC_Visual_Editor_Addon::OPTION_CONTROL_CENTER_ENABLED,
            DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED,
        ] as $option_name) {
            $this->previous_options[$option_name] = get_option($option_name, '__dbvc_missing_option__');
        }
        $this->administrator_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->administrator_id);
    }

    public function tear_down(): void
    {
        foreach ($this->previous_options as $option_name => $value) {
            if ($value === '__dbvc_missing_option__') {
                delete_option($option_name);
            } else {
                update_option($option_name, $value);
            }
        }
        foreach (['bricks', 'brickspreview', 'bricks_preview', '_bricksmode', 'elementor-preview', 'ct_builder', 'oxygen_iframe', 'fl_builder', 'vc_editable'] as $key) {
            unset($_GET[$key]);
        }
        DBVC_Visual_Editor_Addon::refresh_runtime_registration();
        parent::tear_down();
    }

    // ---------------------------------------------------------------------
    // The two-part kill switch — routes registration
    // ---------------------------------------------------------------------

    public function test_is_control_center_enabled_requires_both_master_and_feature_switches(): void
    {
        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '0');
        update_option(DBVC_Visual_Editor_Addon::OPTION_CONTROL_CENTER_ENABLED, '0');
        $this->assertFalse(DBVC_Visual_Editor_Addon::is_control_center_enabled());

        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_CONTROL_CENTER_ENABLED, '0');
        $this->assertFalse(DBVC_Visual_Editor_Addon::is_control_center_enabled());

        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '0');
        update_option(DBVC_Visual_Editor_Addon::OPTION_CONTROL_CENTER_ENABLED, '1');
        $this->assertFalse(DBVC_Visual_Editor_Addon::is_control_center_enabled());

        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_CONTROL_CENTER_ENABLED, '1');
        $this->assertTrue(DBVC_Visual_Editor_Addon::is_control_center_enabled());
    }

    public function test_routes_are_registered_only_when_both_switches_are_on(): void
    {
        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_CONTROL_CENTER_ENABLED, '1');
        DBVC_Visual_Editor_Addon::refresh_runtime_registration();
        $this->initRestRoutes();

        $routes = rest_get_server()->get_routes('dbvc/v1');
        $this->assertRouteRegistered($routes, '/dbvc/v1/visual-editor/session/(?P<session_id>[A-Za-z0-9_-]+)/control-center/controls');
        $this->assertRouteRegistered($routes, '/dbvc/v1/visual-editor/session/(?P<session_id>[A-Za-z0-9_-]+)/control-center/open');
    }

    public function test_routes_are_absent_when_feature_switch_is_off(): void
    {
        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_CONTROL_CENTER_ENABLED, '0');
        DBVC_Visual_Editor_Addon::refresh_runtime_registration();
        $this->initRestRoutes();

        $routes = rest_get_server()->get_routes('dbvc/v1');
        $this->assertRouteAbsent($routes, '/dbvc/v1/visual-editor/session/(?P<session_id>[A-Za-z0-9_-]+)/control-center/controls');
        $this->assertRouteAbsent($routes, '/dbvc/v1/visual-editor/session/(?P<session_id>[A-Za-z0-9_-]+)/control-center/open');
    }

    public function test_routes_are_absent_when_master_switch_is_off(): void
    {
        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '0');
        update_option(DBVC_Visual_Editor_Addon::OPTION_CONTROL_CENTER_ENABLED, '1');
        DBVC_Visual_Editor_Addon::refresh_runtime_registration();
        // Master off unregisters the whole Addon runtime; the REST hook is never bound.
        $this->initRestRoutes();

        $routes = rest_get_server()->get_routes('dbvc/v1');
        // The whole visual-editor route family is absent when master is off.
        $this->assertRouteAbsent($routes, '/dbvc/v1/visual-editor/session/(?P<session_id>[A-Za-z0-9_-]+)/control-center/controls');
        $this->assertRouteAbsent($routes, '/dbvc/v1/visual-editor/session/(?P<session_id>[A-Za-z0-9_-]+)/control-center/open');
    }

    public function test_kill_switch_flip_mid_session_makes_the_routes_unreachable(): void
    {
        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_CONTROL_CENTER_ENABLED, '1');
        DBVC_Visual_Editor_Addon::refresh_runtime_registration();
        $this->initRestRoutes();

        $routes = rest_get_server()->get_routes('dbvc/v1');
        $this->assertRouteRegistered($routes, '/dbvc/v1/visual-editor/session/(?P<session_id>[A-Za-z0-9_-]+)/control-center/controls');

        // Flip the feature switch off and re-refresh runtime — the routes must
        // be gone from the next REST-init dispatch.
        update_option(DBVC_Visual_Editor_Addon::OPTION_CONTROL_CENTER_ENABLED, '0');
        DBVC_Visual_Editor_Addon::refresh_runtime_registration();
        $this->resetRestServer();
        $this->initRestRoutes();

        $routes = rest_get_server()->get_routes('dbvc/v1');
        $this->assertRouteAbsent($routes, '/dbvc/v1/visual-editor/session/(?P<session_id>[A-Za-z0-9_-]+)/control-center/controls');
        $this->assertRouteAbsent($routes, '/dbvc/v1/visual-editor/session/(?P<session_id>[A-Za-z0-9_-]+)/control-center/open');
    }

    // ---------------------------------------------------------------------
    // Bricks Builder isolation — AssetLoader must not enqueue drawer assets
    // inside a builder request.
    // ---------------------------------------------------------------------

    public function test_drawer_assets_do_not_enqueue_inside_bricks_builder(): void
    {
        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_CONTROL_CENTER_ENABLED, '1');

        // Simulate a Bricks Builder request — FrontendRuntimeGuard::isBuilderRequest
        // returns true on any of these query keys, and shouldRunFrontend then
        // short-circuits, which makes shouldLoadFrontendAssets short-circuit,
        // which makes AssetLoader::enqueue return before touching any branch.
        $_GET['bricks'] = '1';

        $this->runAssetLoaderEnqueue();

        $this->assertFalse(
            wp_style_is('dbvc-visual-editor-control-center', 'enqueued'),
            'Drawer stylesheet must not enqueue inside a Bricks Builder request.'
        );
        $this->assertFalse(
            wp_script_is('dbvc-visual-editor-control-center', 'enqueued'),
            'Drawer script must not enqueue inside a Bricks Builder request.'
        );
        // Proves the guard order — media-manager assets also stay off, showing
        // both feature branches sit behind the same FrontendRuntimeGuard exit.
        $this->assertFalse(
            wp_style_is('dbvc-visual-editor-media-manager', 'enqueued'),
            'Media Manager stylesheet must not enqueue inside a Bricks Builder request either.'
        );
    }

    public function test_drawer_assets_do_not_enqueue_when_feature_switch_is_off(): void
    {
        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_CONTROL_CENTER_ENABLED, '0');
        // Force the edit-mode cookie ON so shouldLoadFrontendAssets doesn't
        // short-circuit for the "mode inactive" reason — the switch is the
        // only remaining gate we're testing.
        $_COOKIE['dbvc_visual_editor_mode'] = '1';

        $this->runAssetLoaderEnqueue();

        $this->assertFalse(
            wp_style_is('dbvc-visual-editor-control-center', 'enqueued'),
            'Drawer stylesheet must not enqueue when the Control Center feature switch is off.'
        );
        $this->assertFalse(
            wp_script_is('dbvc-visual-editor-control-center', 'enqueued'),
            'Drawer script must not enqueue when the Control Center feature switch is off.'
        );

        unset($_COOKIE['dbvc_visual_editor_mode']);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Force WP to run its REST init pipeline so registered routes reflect the
     * CURRENT option/kill-switch state. WordPress binds Addon::registerRoutes
     * to rest_api_init but the REST server caches its routes across tests, so
     * we must reset the server first — otherwise a route registered by an
     * earlier assertion persists on the cached server even after the kill
     * switch is flipped off and refresh_runtime_registration() unbinds the
     * rest_api_init action.
     */
    private function initRestRoutes(): void
    {
        $this->resetRestServer();
        do_action('rest_api_init');
    }

    /**
     * Reset the REST server between two registration passes so the second
     * pass reflects the current option state, not a cached routes map from
     * a prior pass in the same test.
     */
    private function resetRestServer(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        rest_get_server();
    }

    /**
     * @param array<string, mixed> $routes
     */
    private function assertRouteRegistered(array $routes, string $path): void
    {
        $this->assertArrayHasKey(
            $path,
            $routes,
            sprintf('Expected REST route %s to be registered.', $path)
        );
    }

    /**
     * @param array<string, mixed> $routes
     */
    private function assertRouteAbsent(array $routes, string $path): void
    {
        $this->assertArrayNotHasKey(
            $path,
            $routes,
            sprintf('Expected REST route %s to be absent (kill switch off).', $path)
        );
    }

    /**
     * Build and run an AssetLoader against the current option / cookie /
     * request-superglobal state, then let the test assert on wp_*_is() side
     * effects.
     */
    private function runAssetLoaderEnqueue(): void
    {
        $capabilities = new \Dbvc\VisualEditor\Permissions\CapabilityManager();
        $page_context = new \Dbvc\VisualEditor\Context\PageContextResolver(null);
        $edit_mode = new \Dbvc\VisualEditor\Context\EditModeState(
            $capabilities,
            $page_context,
            new \Dbvc\VisualEditor\Context\FrontendRuntimeGuard()
        );
        $registry = new \Dbvc\VisualEditor\Registry\EditableRegistry($page_context, null);
        // AssetLoader anchors plugins_url() on this file's dirname to build the
        // asset URLs. The real addon uses the addon's bootstrap.php path (see
        // E-090 for why NOT to use dirname(__DIR__, N) on the plugin root).
        $bootstrap_file = dirname(__DIR__, 2) . '/addons/visual-editor/bootstrap.php';
        $loader = new \Dbvc\VisualEditor\Assets\AssetLoader($bootstrap_file, $edit_mode, $registry, $page_context);
        $loader->enqueue();
    }
}
