<?php

use Dbvc\VisualEditor\Context\EditModeState;
use Dbvc\VisualEditor\Context\FrontendRuntimeGuard;
use Dbvc\VisualEditor\Context\PageContextResolver;
use Dbvc\VisualEditor\HostToolbar\HostToolbarRegistrar;
use Dbvc\VisualEditor\Permissions\CapabilityManager;

/**
 * UT6.2b: the Visual Editor registers its controls in a host toolbar (the
 * Vertical theme's universal toolbar) through `vf_toolbar_register`, and
 * tells the overlay through `DBVCVisualEditorBootstrap.host` whether the
 * host shows them.
 *
 * The theme is not loaded in this suite, so its registry is a recording
 * stub and its `VF_Toolbar::has_item()` a switchable test double.
 */
final class VisualEditorHostToolbarTest extends WP_UnitTestCase
{
    private const COOKIE_NAME = 'dbvc_visual_editor_mode';

    /**
     * @var array<string, mixed>
     */
    private $previous_options = [];

    public function set_up(): void
    {
        parent::set_up();

        foreach ([
            DBVC_Visual_Editor_Addon::OPTION_ENABLED,
            DBVC_Visual_Editor_Addon::OPTION_WORKSPACE_ENABLED,
            DBVC_Visual_Editor_Addon::OPTION_CONTROL_CENTER_ENABLED,
            DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED,
        ] as $option_name) {
            $this->previous_options[$option_name] = get_option($option_name, '__dbvc_missing_option__');
        }

        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_WORKSPACE_ENABLED, '0');
        update_option(DBVC_Visual_Editor_Addon::OPTION_CONTROL_CENTER_ENABLED, '0');
        update_option(DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED, '0');
        DBVC_Visual_Editor_Addon::refresh_runtime_registration();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        Dbvc_Test_VF_Toolbar_Double::$items = [];
        $GLOBALS['wp_scripts'] = null;
    }

    public function tear_down(): void
    {
        unset($_COOKIE[self::COOKIE_NAME]);
        remove_all_filters('dbvc_visual_editor_host_toolbar_enabled');
        remove_all_filters('dbvc_visual_editor_base_capability');
        Dbvc_Test_VF_Toolbar_Double::$items = [];
        $GLOBALS['wp_scripts'] = null;

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

    public function test_the_addon_listens_for_the_host_toolbar_only_while_enabled(): void
    {
        $this->assertNotFalse(has_action('vf_toolbar_register'), 'enabled addon hooks the host registry');

        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '0');
        DBVC_Visual_Editor_Addon::refresh_runtime_registration();

        $this->assertFalse(has_action('vf_toolbar_register'), 'a disabled addon leaves the host toolbar alone');
    }

    public function test_edit_mode_on_registers_the_module_and_the_control_map(): void
    {
        $this->primeFrontendRequest(true);
        $registry = $this->fireHostRegistration();

        $this->assertSame(
            [['id' => 'dbvc-visual-editor', 'owner' => 'dbvc-visual-editor', 'label' => 'Visual Editor']],
            $registry->modules
        );
        $this->assertSame(
            [
                'dbvc-visual-editor/status',
                'dbvc-visual-editor/review-fields',
                'dbvc-visual-editor/go-object',
                'dbvc-visual-editor/shared-globals',
                'dbvc-visual-editor/edit-object',
                'dbvc-visual-editor/settings',
                'dbvc-visual-editor/exit',
            ],
            array_column($registry->items, 'id'),
            'optional surfaces are absent while their switches are off'
        );

        $expected = [
            'status' => ['toggle', 'Visual Editor status', 'context', 'pinned', 'info'],
            'review-fields' => ['toggle', 'Review fields', 'actions', 'normal', 'list'],
            'go-object' => ['toggle', 'Go to object', 'actions', 'normal', 'search'],
            'shared-globals' => ['toggle', 'Shared globals', 'actions', 'overflow', 'globe'],
            'edit-object' => ['button', 'Edit object', 'actions', 'overflow', 'edit'],
            'settings' => ['toggle', 'Visual Editor preferences', 'system', 'overflow', 'settings'],
            'exit' => ['link', 'Exit Visual Editor', 'system', 'overflow', 'power'],
        ];

        foreach ($registry->items as $item) {
            $name = substr($item['id'], strlen('dbvc-visual-editor/'));
            [$type, $label, $zone, $priority, $icon] = $expected[$name];

            $this->assertSame('dbvc-visual-editor', $item['owner'], $name);
            $this->assertSame('dbvc-visual-editor', $item['module'], $name);
            $this->assertSame($type, $item['type'], $name);
            $this->assertSame($label, $item['label'], $name);
            $this->assertSame($zone, $item['zone'], $name);
            $this->assertSame($priority, $item['priority'], $name);
            $this->assertSame($icon, $item['icon'], $name);
            $this->assertSame(['staff'], $item['audience'], $name);
            $this->assertSame('edit_others_posts', $item['capability'], $name);
            $this->assertSame(['site', 'review'], $item['contexts'], "{$name}: never the design context (D7)");
        }

        $exit = $registry->items[6];
        $this->assertStringContainsString('dbvc_visual_editor=0', $exit['href'], 'exit turns edit mode off');
        $this->assertStringContainsString('_dbvcve_nonce=', $exit['href']);
        $this->assertStringNotContainsString('&#038;', $exit['href'], 'raw URL: the host registry escapes it');
    }

    public function test_enabled_surfaces_join_the_tools_zone(): void
    {
        update_option(DBVC_Visual_Editor_Addon::OPTION_WORKSPACE_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_CONTROL_CENTER_ENABLED, '1');
        DBVC_Visual_Editor_Addon::refresh_runtime_registration();
        $this->primeFrontendRequest(true);

        $tools = array_values(array_filter(
            $this->fireHostRegistration()->items,
            static function ($item) {
                return $item['zone'] === 'tools';
            }
        ));

        $this->assertSame(
            [
                ['dbvc-visual-editor/workspace', 'Site Manager', 'layout'],
                ['dbvc-visual-editor/media-manager', 'Media Manager', 'image'],
                ['dbvc-visual-editor/control-center', 'Global Brand Controls', 'sliders'],
            ],
            array_map(
                static function ($item) {
                    return [$item['id'], $item['label'], $item['icon']];
                },
                $tools
            )
        );
    }

    public function test_edit_mode_off_registers_only_the_open_link(): void
    {
        $this->primeFrontendRequest(false);
        $registry = $this->fireHostRegistration();

        $this->assertCount(1, $registry->modules);
        $this->assertSame(['dbvc-visual-editor/open'], array_column($registry->items, 'id'), 'decision D3');

        $open = $registry->items[0];
        $this->assertSame('link', $open['type']);
        $this->assertSame('Open Visual Editor', $open['label']);
        $this->assertSame('tools', $open['zone']);
        $this->assertSame(['site', 'review'], $open['contexts']);
        $this->assertStringContainsString('dbvc_visual_editor=1', $open['href'], 'open turns edit mode on');
        $this->assertStringNotContainsString('&#038;', $open['href']);
    }

    public function test_the_filter_turns_registration_and_docking_off(): void
    {
        $this->primeFrontendRequest(true);
        add_filter('dbvc_visual_editor_host_toolbar_enabled', '__return_false');
        Dbvc_Test_VF_Toolbar_Double::$items = ['dbvc-visual-editor/status'];

        $registry = $this->fireHostRegistration();

        $this->assertSame([], $registry->modules);
        $this->assertSame([], $registry->items);
        $this->assertFalse($this->registrar()->hostBootstrap()['docked'], 'never docked while opted out');
    }

    public function test_items_follow_the_base_capability_and_skip_users_without_it(): void
    {
        add_filter(
            'dbvc_visual_editor_base_capability',
            static function () {
                return 'manage_options';
            }
        );
        $this->primeFrontendRequest(true);

        $items = $this->fireHostRegistration()->items;
        $this->assertNotEmpty($items);
        $this->assertSame(['manage_options'], array_values(array_unique(array_column($items, 'capability'))));

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $registry = $this->fireHostRegistration();
        $this->assertSame([], $registry->modules, 'an editor lacks manage_options, so nothing is described');
        $this->assertSame([], $registry->items);
    }

    public function test_host_bootstrap_is_docked_only_when_the_host_shows_the_status_item(): void
    {
        $registrar = $this->registrar();

        $this->assertSame(
            ['docked' => false, 'module' => 'dbvc-visual-editor', 'readyTimeoutMs' => 4000],
            $registrar->hostBootstrap(),
            'host toolbar present but not rendering our status item'
        );

        Dbvc_Test_VF_Toolbar_Double::$items = ['dbvc-visual-editor/status'];
        $this->assertTrue($registrar->hostBootstrap()['docked']);

        $this->assertSame(
            ['docked' => false, 'module' => 'dbvc-visual-editor', 'readyTimeoutMs' => 4000],
            HostToolbarRegistrar::detachedBootstrap()
        );
    }

    public function test_the_overlay_bootstrap_carries_the_host_block(): void
    {
        $this->primeFrontendRequest(true);
        Dbvc_Test_VF_Toolbar_Double::$items = ['dbvc-visual-editor/status'];

        $capabilities = new CapabilityManager();
        $page_context = new PageContextResolver(null);
        $edit_mode = new EditModeState($capabilities, $page_context, new FrontendRuntimeGuard());
        $loader = new \Dbvc\VisualEditor\Assets\AssetLoader(
            dirname(__DIR__, 2) . '/addons/visual-editor/bootstrap.php',
            $edit_mode,
            new \Dbvc\VisualEditor\Registry\EditableRegistry($page_context, null),
            $page_context,
            new HostToolbarRegistrar($edit_mode, $capabilities)
        );
        $loader->enqueue();

        $data = (string) wp_scripts()->get_data('dbvc-visual-editor-overlay', 'data');
        $this->assertSame(1, preg_match('/var DBVCVisualEditorBootstrap = (.*);\s*$/s', $data, $matches));
        $bootstrap = json_decode($matches[1], true);

        $this->assertSame(
            ['docked' => true, 'module' => 'dbvc-visual-editor', 'readyTimeoutMs' => 4000],
            $bootstrap['host']
        );
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function primeFrontendRequest(bool $edit_mode_on): void
    {
        $post_id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Host Toolbar']);
        $this->go_to(get_permalink($post_id));

        if ($edit_mode_on) {
            $_COOKIE[self::COOKIE_NAME] = '1';
        } else {
            unset($_COOKIE[self::COOKIE_NAME]);
        }
    }

    private function fireHostRegistration(): Dbvc_Test_Host_Toolbar_Registry
    {
        $registry = new Dbvc_Test_Host_Toolbar_Registry();
        do_action('vf_toolbar_register', $registry);

        return $registry;
    }

    private function registrar(): HostToolbarRegistrar
    {
        $capabilities = new CapabilityManager();

        return new HostToolbarRegistrar(
            new EditModeState($capabilities, new PageContextResolver(null), new FrontendRuntimeGuard()),
            $capabilities
        );
    }
}

/**
 * Records what an owner registers, in the shape the theme's
 * VF_Toolbar_Registry::add_module() and ::add() receive.
 */
final class Dbvc_Test_Host_Toolbar_Registry
{
    /** @var array<int, array<string, mixed>> */
    public $modules = [];

    /** @var array<int, array<string, mixed>> */
    public $items = [];

    public function add_module(array $descriptor): bool
    {
        $this->modules[] = $descriptor;

        return true;
    }

    public function add(array $descriptor): bool
    {
        $this->items[] = $descriptor;

        return true;
    }
}

/**
 * Stands in for the theme's VF_Toolbar when the theme is not loaded. Only
 * declared when the real class is absent, so it never shadows the theme.
 */
final class Dbvc_Test_VF_Toolbar_Double
{
    /** @var array<int, string> */
    public static $items = [];

    public static function has_item($item_id): bool
    {
        return in_array($item_id, self::$items, true);
    }
}

if (! class_exists('VF_Toolbar')) {
    class_alias(Dbvc_Test_VF_Toolbar_Double::class, 'VF_Toolbar');
}
