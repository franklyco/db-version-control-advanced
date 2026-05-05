<?php

namespace Dbvc\VisualEditor\Rest;

use Dbvc\VisualEditor\Context\EditModeState;
use Dbvc\VisualEditor\Context\PageContextResolver;
use Dbvc\VisualEditor\Permissions\CapabilityManager;
use Dbvc\VisualEditor\Presentation\DescriptorSummaryBuilder;
use Dbvc\VisualEditor\Registry\EditableRegistry;
use Dbvc\VisualEditor\Resolvers\ResolverRegistry;
use Dbvc\VisualEditor\Rest\DescriptorPayloadBuilder;
use Dbvc\VisualEditor\Rest\Controllers\DescriptorController;
use Dbvc\VisualEditor\Rest\Controllers\SaveController;
use Dbvc\VisualEditor\Rest\Controllers\SessionController;
use Dbvc\VisualEditor\Save\MutationContractService;
use Dbvc\VisualEditor\Save\MutationService;

final class Routes
{
    /**
     * @var EditableRegistry
     */
    private $registry;

    /**
     * @var ResolverRegistry
     */
    private $resolvers;

    /**
     * @var MutationService
     */
    private $mutations;

    /**
     * @var EditModeState
     */
    private $edit_mode;

    /**
     * @var PageContextResolver
     */
    private $page_context;

    /**
     * @var CapabilityManager
     */
    private $capabilities;

    /**
     * @var DescriptorSummaryBuilder
     */
    private $summaries;

    public function __construct(
        EditableRegistry $registry,
        ResolverRegistry $resolvers,
        MutationService $mutations,
        EditModeState $edit_mode,
        PageContextResolver $page_context,
        CapabilityManager $capabilities,
        DescriptorSummaryBuilder $summaries
    ) {
        $this->registry = $registry;
        $this->resolvers = $resolvers;
        $this->mutations = $mutations;
        $this->edit_mode = $edit_mode;
        $this->page_context = $page_context;
        $this->capabilities = $capabilities;
        $this->summaries = $summaries;
    }

    /**
     * @return void
     */
    public function register()
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * @return void
     */
    public function unregister()
    {
        remove_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * @return void
     */
    public function registerRoutes()
    {
        $contracts = new MutationContractService();
        $payloads = new DescriptorPayloadBuilder($this->resolvers, $this->capabilities, $this->summaries, $contracts);

        (new SessionController($this->registry, $this->edit_mode, $this->page_context, $this->capabilities, $payloads))->register();
        (new DescriptorController($this->registry, $payloads, $this->edit_mode, $this->capabilities))->register();
        (new SaveController($this->registry, $this->mutations, $this->edit_mode, $this->capabilities, $contracts))->register();
    }
}
