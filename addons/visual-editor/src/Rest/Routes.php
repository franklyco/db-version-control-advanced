<?php

namespace Dbvc\VisualEditor\Rest;

use Dbvc\VisualEditor\Context\EditModeState;
use Dbvc\VisualEditor\Context\PageContextResolver;
use Dbvc\VisualEditor\MediaManager\MediaScanCoordinator;
use Dbvc\VisualEditor\MediaManager\MediaScanReadModel;
use Dbvc\VisualEditor\Performance\PerformanceProfiler;
use Dbvc\VisualEditor\Permissions\CapabilityManager;
use Dbvc\VisualEditor\Presentation\DescriptorSummaryBuilder;
use Dbvc\VisualEditor\Registry\EditableRegistry;
use Dbvc\VisualEditor\Resolvers\ResolverRegistry;
use Dbvc\VisualEditor\Rest\DescriptorPayloadBuilder;
use Dbvc\VisualEditor\Rest\Controllers\CollectionSeedController;
use Dbvc\VisualEditor\Rest\Controllers\CompositeSaveController;
use Dbvc\VisualEditor\Rest\Controllers\DescriptorController;
use Dbvc\VisualEditor\MediaManager\MediaAssignmentService;
use Dbvc\VisualEditor\MediaManager\MediaFindingDescriptorBridge;
use Dbvc\VisualEditor\Rest\Controllers\MediaManagerController;
use Dbvc\VisualEditor\Rest\Controllers\ObjectSearchController;
use Dbvc\VisualEditor\Rest\Controllers\ReferenceSearchController;
use Dbvc\VisualEditor\Rest\Controllers\SaveController;
use Dbvc\VisualEditor\Rest\Controllers\SessionController;
use Dbvc\VisualEditor\Rest\Controllers\SharedGlobalFieldsController;
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

    /**
     * @var PerformanceProfiler|null
     */
    private $profiler;

    /**
     * @var MediaScanCoordinator
     */
    private $media_scans;

    /**
     * @var MediaScanReadModel
     */
    private $media_read_model;

    /**
     * @var MediaFindingDescriptorBridge
     */
    private $media_descriptor_bridge;

    /**
     * @var MediaAssignmentService
     */
    private $media_assignment_service;

    public function __construct(
        EditableRegistry $registry,
        ResolverRegistry $resolvers,
        MutationService $mutations,
        EditModeState $edit_mode,
        PageContextResolver $page_context,
        CapabilityManager $capabilities,
        DescriptorSummaryBuilder $summaries,
        ?PerformanceProfiler $profiler,
        MediaScanCoordinator $media_scans,
        MediaScanReadModel $media_read_model,
        MediaFindingDescriptorBridge $media_descriptor_bridge,
        MediaAssignmentService $media_assignment_service
    ) {
        $this->registry = $registry;
        $this->resolvers = $resolvers;
        $this->mutations = $mutations;
        $this->edit_mode = $edit_mode;
        $this->page_context = $page_context;
        $this->capabilities = $capabilities;
        $this->summaries = $summaries;
        $this->profiler = $profiler;
        $this->media_scans = $media_scans;
        $this->media_read_model = $media_read_model;
        $this->media_descriptor_bridge = $media_descriptor_bridge;
        $this->media_assignment_service = $media_assignment_service;
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
        $payloads = new DescriptorPayloadBuilder($this->resolvers, $this->capabilities, $this->summaries, $contracts, $this->profiler);

        (new SessionController($this->registry, $this->edit_mode, $this->page_context, $this->capabilities, $payloads))->register();
        (new DescriptorController($this->registry, $payloads, $this->edit_mode, $this->capabilities))->register();
        (new ReferenceSearchController($this->registry, $this->resolvers, $this->edit_mode, $this->capabilities))->register();
        (new ObjectSearchController($this->edit_mode, $this->capabilities))->register();
        (new SharedGlobalFieldsController($this->registry, $this->edit_mode, $this->capabilities, $payloads))->register();
        (new MediaManagerController($this->media_scans, $this->media_read_model, $this->edit_mode, $this->capabilities, $this->media_descriptor_bridge, $this->media_assignment_service))->register();
        (new CollectionSeedController($this->registry, $this->mutations, $this->edit_mode, $this->capabilities, $contracts))->register();
        (new CompositeSaveController($this->registry, $this->mutations, $this->edit_mode, $this->capabilities, $contracts))->register();
        (new SaveController($this->registry, $this->mutations, $this->edit_mode, $this->capabilities, $contracts))->register();
    }
}
