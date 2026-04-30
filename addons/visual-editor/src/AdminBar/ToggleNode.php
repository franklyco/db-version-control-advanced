<?php

namespace Dbvc\VisualEditor\AdminBar;

use Dbvc\VisualEditor\Context\EditModeState;
use Dbvc\VisualEditor\Permissions\CapabilityManager;
use WP_Admin_Bar;

final class ToggleNode
{
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
        add_action('admin_bar_menu', [$this, 'addNode'], 200);
    }

    /**
     * @return void
     */
    public function unregister()
    {
        remove_action('admin_bar_menu', [$this, 'addNode'], 200);
    }

    /**
     * @param WP_Admin_Bar $admin_bar
     * @return void
     */
    public function addNode($admin_bar)
    {
        if (! ($admin_bar instanceof WP_Admin_Bar) || ! $this->capabilities->canUseVisualEditor() || ! $this->edit_mode->canRenderToggle()) {
            return;
        }

        $admin_bar->add_node(
            [
                'id' => 'dbvc-visual-editor',
                'title' => $this->edit_mode->isActive() ? __('Exit Visual Editor', 'dbvc') : __('Open Visual Editor', 'dbvc'),
                'href' => $this->edit_mode->buildToggleUrl(),
                'meta' => [
                    'class' => 'dbvc-visual-editor-toggle',
                ],
            ]
        );
    }
}
