<?php

namespace Dbvc\VisualEditor\Bricks;

use Dbvc\VisualEditor\Context\EditModeState;
use Dbvc\VisualEditor\Context\PageContextResolver;
use Dbvc\VisualEditor\Registry\EditableRegistry;
use Dbvc\VisualEditor\Resolvers\ResolverRegistry;

final class HookRegistrar
{
    /**
     * @var EditModeState
     */
    private $edit_mode;

    /**
     * @var EditableRegistry
     */
    private $registry;

    /**
     * @var PageContextResolver
     */
    private $page_context;

    /**
     * @var ResolverRegistry
     */
    private $resolvers;

    /**
     * @var LoopContextResolver
     */
    private $loops;

    /**
     * @var ElementInstrumentationService|null
     */
    private $service = null;

    public function __construct(EditModeState $edit_mode, EditableRegistry $registry, PageContextResolver $page_context, ResolverRegistry $resolvers, ?LoopContextResolver $loops = null)
    {
        $this->edit_mode = $edit_mode;
        $this->registry = $registry;
        $this->page_context = $page_context;
        $this->resolvers = $resolvers;
        $this->loops = $loops instanceof LoopContextResolver ? $loops : new LoopContextResolver();
    }

    /**
     * @return void
     */
    public function register()
    {
        add_action('wp', [$this, 'maybeRegisterHooks'], 20);
    }

    /**
     * @return void
     */
    public function unregister()
    {
        remove_action('wp', [$this, 'maybeRegisterHooks'], 20);

        if ($this->service instanceof ElementInstrumentationService) {
            remove_filter('bricks/element/render_attributes', [$this->service, 'instrumentAttributes'], 20);
            remove_filter('bricks/frontend/render_element', [$this->service, 'verifyRenderedElement'], 20);
            remove_filter('bricks/frontend/render_data', [$this->service, 'finalizeRenderedData'], 20);
        }
    }

    /**
     * @return void
     */
    public function maybeRegisterHooks()
    {
        if (! $this->edit_mode->shouldLoadFrontendAssets() || ! $this->isBricksAvailable()) {
            return;
        }

        if (! ($this->service instanceof ElementInstrumentationService)) {
            $this->service = new ElementInstrumentationService($this->registry, $this->page_context, $this->resolvers, $this->loops);
        }

        add_filter('bricks/element/render_attributes', [$this->service, 'instrumentAttributes'], 20, 3);
        add_filter('bricks/frontend/render_element', [$this->service, 'verifyRenderedElement'], 20, 2);
        add_filter('bricks/frontend/render_data', [$this->service, 'finalizeRenderedData'], 20, 3);
    }

    /**
     * @return bool
     */
    private function isBricksAvailable()
    {
        return defined('BRICKS_VERSION') || class_exists('Bricks\\Frontend');
    }
}
