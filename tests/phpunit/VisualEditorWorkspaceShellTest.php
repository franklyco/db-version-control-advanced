<?php

/**
 * R6-D-1: Site Manager Workspace kill switch, settings wiring, bootstrap
 * payload, and asset enqueue gating.
 *
 * Mirrors VisualEditorControlCenterHardeningTest's shape: the flag is a
 * two-part switch (master AND feature), default off, and both the asset
 * enqueue and the `workspace` bootstrap block fail closed when either part
 * is off or the request is a builder request.
 */
final class VisualEditorWorkspaceShellTest extends WP_UnitTestCase
{
    private const COOKIE_NAME = 'dbvc_visual_editor_mode';

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
            DBVC_Visual_Editor_Addon::OPTION_WORKSPACE_ENABLED,
            DBVC_Visual_Editor_Addon::OPTION_CONTROL_CENTER_ENABLED,
            DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED,
            DBVC_Visual_Editor_Addon::OPTION_SETTINGS_VERSION,
        ] as $option_name) {
            $this->previous_options[$option_name] = get_option($option_name, '__dbvc_missing_option__');
        }

        $this->administrator_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->administrator_id);
        $this->resetAssetQueues();
    }

    public function tear_down(): void
    {
        unset($_COOKIE[self::COOKIE_NAME], $_GET['bricks']);
        $this->resetAssetQueues();

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

    // ---------------------------------------------------------------------
    // Kill switch + settings wiring
    // ---------------------------------------------------------------------

    public function test_workspace_setting_is_default_off_and_requires_master_switch(): void
    {
        delete_option(DBVC_Visual_Editor_Addon::OPTION_WORKSPACE_ENABLED);
        update_option(DBVC_Visual_Editor_Addon::OPTION_SETTINGS_VERSION, '6');
        DBVC_Visual_Editor_Addon::ensure_defaults();

        $this->assertSame('0', get_option(DBVC_Visual_Editor_Addon::OPTION_WORKSPACE_ENABLED));
        $this->assertSame('9', get_option(DBVC_Visual_Editor_Addon::OPTION_SETTINGS_VERSION), 'R6-D-1 bumped SETTINGS_VERSION 6 → 7; R5.later-perf-a 7 → 8; perf-b 8 → 9');
        $this->assertFalse(DBVC_Visual_Editor_Addon::is_workspace_enabled());

        update_option(DBVC_Visual_Editor_Addon::OPTION_WORKSPACE_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '0');
        $this->assertFalse(DBVC_Visual_Editor_Addon::is_workspace_enabled(), 'feature switch alone is not enough');

        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        $this->assertTrue(DBVC_Visual_Editor_Addon::is_workspace_enabled());
    }

    public function test_workspace_setting_is_exposed_in_settings_ui_and_round_trips_through_save(): void
    {
        $groups = DBVC_Visual_Editor_Addon::get_settings_groups();
        $meta = DBVC_Visual_Editor_Addon::get_field_meta();

        $this->assertArrayHasKey('workspace', $groups);
        $this->assertContains(DBVC_Visual_Editor_Addon::OPTION_WORKSPACE_ENABLED, $groups['workspace']['fields']);
        $this->assertSame('checkbox', $meta[DBVC_Visual_Editor_Addon::OPTION_WORKSPACE_ENABLED]['input']);
        $this->assertArrayHasKey(DBVC_Visual_Editor_Addon::OPTION_WORKSPACE_ENABLED, DBVC_Visual_Editor_Addon::get_all_settings());

        $saved = DBVC_Visual_Editor_Addon::save_settings([
            DBVC_Visual_Editor_Addon::OPTION_ENABLED => '1',
            DBVC_Visual_Editor_Addon::OPTION_WORKSPACE_ENABLED => '1',
        ]);
        $this->assertSame('1', $saved['values'][DBVC_Visual_Editor_Addon::OPTION_WORKSPACE_ENABLED]);
        $this->assertTrue(DBVC_Visual_Editor_Addon::is_workspace_enabled());

        $saved = DBVC_Visual_Editor_Addon::save_settings([
            DBVC_Visual_Editor_Addon::OPTION_ENABLED => '1',
        ]);
        $this->assertSame('0', $saved['values'][DBVC_Visual_Editor_Addon::OPTION_WORKSPACE_ENABLED], 'unchecked checkbox saves as off');
        $this->assertFalse(DBVC_Visual_Editor_Addon::is_workspace_enabled());
    }

    // ---------------------------------------------------------------------
    // Asset enqueue + bootstrap payload
    // ---------------------------------------------------------------------

    public function test_workspace_assets_and_bootstrap_block_are_present_only_when_enabled(): void
    {
        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_WORKSPACE_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_CONTROL_CENTER_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED, '0');
        $this->primeFrontendRequest();

        $this->runAssetLoaderEnqueue();

        $this->assertTrue(wp_style_is('dbvc-visual-editor-workspace', 'enqueued'));
        $this->assertTrue(wp_script_is('dbvc-visual-editor-workspace', 'enqueued'));

        $deps = wp_scripts()->registered['dbvc-visual-editor-workspace']->deps;
        $this->assertContains('dbvc-visual-editor-overlay', $deps);
        $this->assertContains('dbvc-visual-editor-control-center', $deps, 'depends on the BCC script when that is enabled (Escape-handler ordering)');
        $this->assertNotContains('dbvc-visual-editor-media-manager', $deps, 'no dependency on a disabled Media Manager');

        $bootstrap = $this->bootstrapPayload();
        $this->assertSame(true, $bootstrap['workspace']['enabled']);
        // R6-D-2: PageContextResolver emits a safe display title for the current-object card.
        $this->assertSame('Workspace Shell', $bootstrap['pageContext']['title']);
        $this->assertStringContainsString('/dbvc/v1/visual-editor', $bootstrap['workspace']['restBase']);
        $this->assertSame('Site Manager', $bootstrap['strings']['toolbarWorkspace']);
        $this->assertSame('Site Manager', $bootstrap['strings']['workspaceTitle']);
        foreach ([
            'workspaceClose', 'workspaceSectionNavigate', 'workspaceSectionTools', 'workspaceCurrentHere',
            'workspaceStatusIdle', 'workspaceToolReviewFields', 'workspaceToolControlCenter',
            'workspaceToolMediaManager', 'workspaceToolEditObject', 'workspaceToolExit',
            'workspaceOpensNewTab', 'workspaceNoPublicPage', 'workspaceNoPublicArchive',
            // R6.1-b: kind filter + sort select.
            'workspaceKindLabel', 'workspaceKindAll', 'workspaceKindContent', 'workspaceKindTaxonomies',
            'workspaceSortLabel', 'workspaceSortRecent', 'workspaceSortTitleAsc', 'workspaceSortTitleDesc',
            'workspaceSortNewest', 'workspaceSortOldest', 'workspaceSortRelevance',
            // VE-prefs-2: Site Manager startup preference strings ship with the toolbar family.
            'preferencesWorkspaceLabel', 'preferencesWorkspaceRemember', 'preferencesWorkspaceOpen',
            'preferencesWorkspaceClosed', 'preferencesWorkspaceHint', 'preferencesAnnounceWorkspace',
        ] as $key) {
            $this->assertArrayHasKey($key, $bootstrap['strings'], "i18n key {$key} present");
        }
    }

    public function test_workspace_assets_and_bootstrap_block_are_absent_when_feature_switch_is_off(): void
    {
        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_WORKSPACE_ENABLED, '0');
        $this->primeFrontendRequest();

        $this->runAssetLoaderEnqueue();

        $this->assertTrue(wp_script_is('dbvc-visual-editor-overlay', 'enqueued'), 'sanity: the overlay itself did enqueue');
        $this->assertFalse(wp_style_is('dbvc-visual-editor-workspace', 'enqueued'));
        $this->assertFalse(wp_script_is('dbvc-visual-editor-workspace', 'enqueued'));

        $bootstrap = $this->bootstrapPayload();
        $this->assertSame(false, $bootstrap['workspace']['enabled'], 'bootstrap block is present but disabled so overlay-app renders no toolbar button');
    }

    public function test_workspace_assets_do_not_enqueue_inside_bricks_builder(): void
    {
        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_WORKSPACE_ENABLED, '1');
        $this->primeFrontendRequest();
        $_GET['bricks'] = '1';

        $this->runAssetLoaderEnqueue();

        $this->assertFalse(wp_style_is('dbvc-visual-editor-workspace', 'enqueued'));
        $this->assertFalse(wp_script_is('dbvc-visual-editor-workspace', 'enqueued'));
        $this->assertFalse(wp_script_is('dbvc-visual-editor-overlay', 'enqueued'), 'the whole overlay stack stays off inside the builder');
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Put WordPress on a supported singular frontend request with the
     * Visual Editor mode cookie on, so EditModeState::shouldLoadFrontendAssets
     * passes and AssetLoader::enqueue reaches the feature branches.
     */
    private function primeFrontendRequest(): void
    {
        $post_id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Workspace Shell']);
        $this->go_to(get_permalink($post_id));
        $_COOKIE[self::COOKIE_NAME] = '1';
    }

    /**
     * wp_scripts() / wp_styles() are process-global; a handle enqueued by one
     * test would otherwise still read as enqueued in the next.
     */
    private function resetAssetQueues(): void
    {
        $GLOBALS['wp_scripts'] = null;
        $GLOBALS['wp_styles'] = null;
    }

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
        $bootstrap_file = dirname(__DIR__, 2) . '/addons/visual-editor/bootstrap.php';
        $loader = new \Dbvc\VisualEditor\Assets\AssetLoader($bootstrap_file, $edit_mode, $registry, $page_context);
        $loader->enqueue();
    }

    /**
     * Decode the `DBVCVisualEditorBootstrap` object wp_localize_script attached
     * to the overlay handle.
     *
     * @return array<string, mixed>
     */
    private function bootstrapPayload(): array
    {
        $data = (string) wp_scripts()->get_data('dbvc-visual-editor-overlay', 'data');
        $this->assertNotSame('', $data, 'overlay bootstrap data should be localized');

        if (! preg_match('/var DBVCVisualEditorBootstrap = (.*);\s*$/s', $data, $matches)) {
            $this->fail('DBVCVisualEditorBootstrap not found in localized data.');
        }

        $decoded = json_decode($matches[1], true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
