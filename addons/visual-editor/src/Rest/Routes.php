<?php

namespace Dbvc\VisualEditor\Rest;

use Dbvc\VisualEditor\Context\EditModeState;
use Dbvc\VisualEditor\Context\PageContextResolver;
use Dbvc\VisualEditor\MediaManager\MediaScanCoordinator;
use Dbvc\VisualEditor\MediaManager\MediaScanReadModel;
use Dbvc\VisualEditor\Performance\PerformanceProfiler;
use Dbvc\VisualEditor\Permissions\CapabilityManager;
use Dbvc\VisualEditor\Presentation\DescriptorSummaryBuilder;
use Dbvc\VisualEditor\Registry\ControlRegistry;
use Dbvc\VisualEditor\Registry\EditableRegistry;
use Dbvc\VisualEditor\Resolvers\ResolverRegistry;
use Dbvc\VisualEditor\Rest\DescriptorPayloadBuilder;
use Dbvc\VisualEditor\Rest\Controllers\CollectionSeedController;
use Dbvc\VisualEditor\Rest\Controllers\CompositeSaveController;
use Dbvc\VisualEditor\Rest\Controllers\ControlCenterListController;
use Dbvc\VisualEditor\Rest\Controllers\ControlCenterOpenController;
use Dbvc\VisualEditor\Rest\Controllers\ControlCenterValueSummariesController;
use Dbvc\VisualEditor\Rest\Controllers\DescriptorController;
use Dbvc\VisualEditor\MediaManager\MediaAssignmentService;
use Dbvc\VisualEditor\MediaManager\MediaFindingDescriptorBridge;
use Dbvc\VisualEditor\MediaManager\MediaIndexReadModel;
use Dbvc\VisualEditor\MediaManager\MediaIndexStore;
use Dbvc\VisualEditor\MediaManager\EligibilityPolicy;
use Dbvc\VisualEditor\Rest\Controllers\MediaIndexController;
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

    /**
     * @var MediaIndexReadModel
     */
    private $media_index_read_model;

    /**
     * @var MediaIndexStore
     */
    private $media_index_store;

    /**
     * @var EligibilityPolicy
     */
    private $media_policy;

    /**
     * @var ControlRegistry
     */
    private $control_registry;

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
        MediaAssignmentService $media_assignment_service,
        MediaIndexReadModel $media_index_read_model,
        MediaIndexStore $media_index_store,
        EligibilityPolicy $media_policy,
        ControlRegistry $control_registry
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
        $this->media_index_read_model = $media_index_read_model;
        $this->media_index_store = $media_index_store;
        $this->media_policy = $media_policy;
        $this->control_registry = $control_registry;
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
        // R3-C-1: Brand Control Center routes — discovery-only list + open-time
        // descriptor factory delegating through the ControlRegistry. Kill-switch
        // gated (`is_control_center_enabled()` requires both master Visual Editor
        // switch and this switch, both default off — D-063). No new write authority.
        if (class_exists('\\DBVC_Visual_Editor_Addon')
            && method_exists('\\DBVC_Visual_Editor_Addon', 'is_control_center_enabled')
            && \DBVC_Visual_Editor_Addon::is_control_center_enabled()) {
            (new ControlCenterListController($this->control_registry, $this->registry, $this->edit_mode, $this->capabilities))->register();
            (new ControlCenterOpenController($this->control_registry, $this->registry, $this->edit_mode, $this->capabilities, $payloads))->register();
            // R4-A: batch value-summary endpoint. Same kill-switch gate as
            // the R3-C-1 routes; discovery-only per-record read-model with
            // per-record capability recheck before the provider's summary
            // factory runs.
            (new ControlCenterValueSummariesController($this->control_registry, $this->registry, $this->edit_mode, $this->capabilities))->register();
        }
        (new MediaManagerController($this->media_scans, $this->media_read_model, $this->edit_mode, $this->capabilities, $this->media_descriptor_bridge, $this->media_assignment_service))->register();
        (new MediaIndexController($this->capabilities, $this->media_index_read_model, $this->media_index_store, $this->media_scans, $this->media_read_model, $this->media_policy))->register();
        (new CollectionSeedController($this->registry, $this->mutations, $this->edit_mode, $this->capabilities, $contracts))->register();
        (new CompositeSaveController($this->registry, $this->mutations, $this->edit_mode, $this->capabilities, $contracts))->register();
        (new SaveController($this->registry, $this->mutations, $this->edit_mode, $this->capabilities, $contracts))->register();
    }
}
