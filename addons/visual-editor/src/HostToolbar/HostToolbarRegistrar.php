<?php

namespace Dbvc\VisualEditor\HostToolbar;

use Dbvc\VisualEditor\Context\EditModeState;
use Dbvc\VisualEditor\Permissions\CapabilityManager;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * UT6.2b of the Vertical theme's DBVC toolbar adapter: registers the Visual
 * Editor's controls as items in a host toolbar, the theme's universal
 * toolbar, when one is present.
 *
 * The theme fires `vf_toolbar_register` with its registry on
 * template_redirect (priority 20); without the theme the action never fires
 * and nothing here runs, so the Visual Editor keeps its own toolbar. The
 * theme's registry still decides who sees each item (staff, the capability,
 * the context); this class only describes them.
 *
 * - Edit mode on: the module `dbvc-visual-editor` and one item per Visual
 *   Editor control (the adapter contract's control map).
 * - Edit mode off but the toggle would show: only the "Open Visual Editor"
 *   link (decision D3).
 * - The `dbvc_visual_editor_host_toolbar_enabled` filter (default true)
 *   turns all of it off, and the Visual Editor keeps its own toolbar.
 *
 * Docking the controls (hiding the Visual Editor's own toolbar and binding
 * the items) is the overlay's job once the theme's toolbar reports ready
 * (UT6.2d); this class tells it whether to try through hostBootstrap().
 */
final class HostToolbarRegistrar
{
    /** Module and item owner id in the host toolbar. */
    public const MODULE = 'dbvc-visual-editor';

    /** Item the overlay checks to know it is docked. */
    public const STATUS_ITEM = self::MODULE . '/status';

    /** How long the overlay waits for the host toolbar before keeping its own. */
    public const READY_TIMEOUT_MS = 4000;

    /** Host toolbar contexts the items appear in (decision D7: never `design`). */
    private const CONTEXTS = ['site', 'review'];

    /**
     * @var EditModeState
     */
    private $edit_mode;

    /**
     * @var CapabilityManager
     */
    private $capabilities;

    public function __construct(EditModeState $edit_mode, CapabilityManager $capabilities)
    {
        $this->edit_mode = $edit_mode;
        $this->capabilities = $capabilities;
    }

    /**
     * @return void
     */
    public function register()
    {
        add_action('vf_toolbar_register', [$this, 'registerItems'], 10, 1);
    }

    /**
     * @return void
     */
    public function unregister()
    {
        remove_action('vf_toolbar_register', [$this, 'registerItems'], 10);
    }

    /**
     * Whether the Visual Editor may put its controls in a host toolbar.
     *
     * @return bool
     */
    public function isEnabled()
    {
        /**
         * Let the Visual Editor dock its controls in a host toolbar (the
         * Vertical theme's universal toolbar). Return false to keep the
         * Visual Editor's own toolbar everywhere.
         *
         * @param bool $enabled Default true.
         */
        return (bool) apply_filters('dbvc_visual_editor_host_toolbar_enabled', true);
    }

    /**
     * `vf_toolbar_register` callback: describe the Visual Editor's controls
     * to the host toolbar's registry.
     *
     * @param object $registry The host toolbar's registry (VF_Toolbar_Registry).
     * @return void
     */
    public function registerItems($registry)
    {
        if (! is_object($registry) || ! method_exists($registry, 'add') || ! method_exists($registry, 'add_module')) {
            return;
        }

        if (! $this->isEnabled() || ! $this->capabilities->canUseVisualEditor()) {
            return;
        }

        if ($this->edit_mode->isActive()) {
            $items = $this->activeItems();
        } elseif ($this->edit_mode->canRenderToggle()) {
            $items = [$this->openItem()];
        } else {
            return;
        }

        if (! $registry->add_module(
            [
                'id' => self::MODULE,
                'owner' => self::MODULE,
                'label' => __('Visual Editor', 'dbvc'),
            ]
        )) {
            return;
        }

        foreach ($items as $item) {
            $registry->add($item);
        }
    }

    /**
     * The `DBVCVisualEditorBootstrap.host` block for the overlay.
     *
     * `docked` is true only when the host toolbar is rendering this viewer's
     * status item, so the overlay never hides its own toolbar for a host that
     * will not show the controls.
     *
     * @return array{docked: bool, module: string, readyTimeoutMs: int}
     */
    public function hostBootstrap()
    {
        return [
            'docked' => $this->isDocked(),
            'module' => self::MODULE,
            'readyTimeoutMs' => self::READY_TIMEOUT_MS,
        ];
    }

    /**
     * The host block when no registrar is wired (the overlay keeps its own
     * toolbar).
     *
     * @return array{docked: bool, module: string, readyTimeoutMs: int}
     */
    public static function detachedBootstrap()
    {
        return [
            'docked' => false,
            'module' => self::MODULE,
            'readyTimeoutMs' => self::READY_TIMEOUT_MS,
        ];
    }

    /**
     * @return bool
     */
    private function isDocked()
    {
        if (! $this->isEnabled() || ! class_exists('\\VF_Toolbar') || ! method_exists('\\VF_Toolbar', 'has_item')) {
            return false;
        }

        return (bool) \VF_Toolbar::has_item(self::STATUS_ITEM);
    }

    /**
     * Items while edit mode is on, in the Visual Editor's toolbar order,
     * placed by the adapter contract's control map (decision D4).
     *
     * @return array<int, array<string, mixed>>
     */
    private function activeItems()
    {
        $items = [
            $this->item('status', 'toggle', __('Visual Editor status', 'dbvc'), 'context', 10, 'pinned', 'info'),
        ];

        if ($this->addonFlag('is_workspace_enabled')) {
            $items[] = $this->item('workspace', 'toggle', __('Site Manager', 'dbvc'), 'tools', 10, 'normal', 'layout');
        }

        $items[] = $this->item('review-fields', 'toggle', __('Review fields', 'dbvc'), 'actions', 10, 'normal', 'list');
        $items[] = $this->item('go-object', 'toggle', __('Go to object', 'dbvc'), 'actions', 20, 'normal', 'search');

        if ($this->addonFlag('is_media_manager_enabled')) {
            $items[] = $this->item('media-manager', 'toggle', __('Media Manager', 'dbvc'), 'tools', 20, 'normal', 'image');
        }

        $items[] = $this->item('shared-globals', 'toggle', __('Shared globals', 'dbvc'), 'actions', 30, 'overflow', 'globe');

        if ($this->addonFlag('is_control_center_enabled')) {
            $items[] = $this->item('control-center', 'toggle', __('Global Brand Controls', 'dbvc'), 'tools', 30, 'normal', 'sliders');
        }

        // An item's href can't change after the server sends it, so the
        // current edit link is opened by the bridge on select (UT6.2d).
        $items[] = $this->item('edit-object', 'button', __('Edit object', 'dbvc'), 'actions', 40, 'overflow', 'edit');
        $items[] = $this->item('settings', 'toggle', __('Visual Editor preferences', 'dbvc'), 'system', 10, 'overflow', 'settings');

        $exit = $this->item('exit', 'link', __('Exit Visual Editor', 'dbvc'), 'system', 20, 'overflow', 'power');
        $exit['href'] = $this->edit_mode->buildToggleUrlRaw();
        $items[] = $exit;

        return $items;
    }

    /**
     * The launch link while edit mode is off (decision D3).
     *
     * @return array<string, mixed>
     */
    private function openItem()
    {
        $open = $this->item('open', 'link', __('Open Visual Editor', 'dbvc'), 'tools', 40, 'normal', 'edit');
        $open['href'] = $this->edit_mode->buildToggleUrlRaw();

        return $open;
    }

    /**
     * One item descriptor in the host registry's shape. Icons the host's
     * sprite lacks (search, sliders, power until the theme's UT6.2c) are
     * dropped by the registry, and the label shows instead.
     *
     * @param string $name     Item name; the id is `dbvc-visual-editor/<name>`.
     * @param string $type     toggle, button, or link.
     * @param string $label    Translated label.
     * @param string $zone     context, actions, tools, or system.
     * @param int    $order    Position within the zone.
     * @param string $priority pinned, normal, or overflow.
     * @param string $icon     Sprite symbol.
     * @return array<string, mixed>
     */
    private function item($name, $type, $label, $zone, $order, $priority, $icon)
    {
        return [
            'id' => self::MODULE . '/' . $name,
            'owner' => self::MODULE,
            'module' => self::MODULE,
            'type' => $type,
            'label' => $label,
            'icon' => $icon,
            'zone' => $zone,
            'order' => $order,
            'priority' => $priority,
            'audience' => ['staff'],
            'capability' => $this->capabilities->baseCapability(),
            'contexts' => self::CONTEXTS,
        ];
    }

    /**
     * @param string $method A `DBVC_Visual_Editor_Addon` feature check.
     * @return bool
     */
    private function addonFlag($method)
    {
        return class_exists('\\DBVC_Visual_Editor_Addon')
            && method_exists('\\DBVC_Visual_Editor_Addon', $method)
            && (bool) call_user_func(['\\DBVC_Visual_Editor_Addon', $method]);
    }
}
