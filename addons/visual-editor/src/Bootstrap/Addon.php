<?php

namespace Dbvc\VisualEditor\Bootstrap;

use Dbvc\VisualEditor\AdminBar\ToggleNode;
use Dbvc\VisualEditor\Assets\AssetLoader;
use Dbvc\VisualEditor\Audit\ChangeLogger;
use Dbvc\VisualEditor\Bricks\HookRegistrar;
use Dbvc\VisualEditor\Bricks\LoopContextResolver;
use Dbvc\VisualEditor\Cache\CacheInvalidator;
use Dbvc\VisualEditor\Context\EditModeState;
use Dbvc\VisualEditor\Context\FrontendRuntimeGuard;
use Dbvc\VisualEditor\Context\PageContextResolver;
use Dbvc\VisualEditor\Journal\ChangeJournalRecorder;
use Dbvc\VisualEditor\Journal\ChangeJournalStore;
use Dbvc\VisualEditor\MediaManager\AcfMediaFieldCatalog;
use Dbvc\VisualEditor\MediaManager\EligibilityPolicy;
use Dbvc\VisualEditor\MediaManager\MediaAssignmentService;
use Dbvc\VisualEditor\MediaManager\MediaFindingDescriptorBridge;
use Dbvc\VisualEditor\MediaManager\MediaAssignmentValueClassifier;
use Dbvc\VisualEditor\MediaManager\MediaIndexBuilder;
use Dbvc\VisualEditor\MediaManager\MediaIndexInvalidator;
use Dbvc\VisualEditor\MediaManager\MediaIndexProjector;
use Dbvc\VisualEditor\MediaManager\MediaIndexReadModel;
use Dbvc\VisualEditor\MediaManager\MediaIndexReconciler;
use Dbvc\VisualEditor\MediaManager\MediaIndexScheduler;
use Dbvc\VisualEditor\MediaManager\MediaIndexStore;
use Dbvc\VisualEditor\MediaManager\MediaScanCoordinator;
use Dbvc\VisualEditor\MediaManager\MediaScanReadModel;
use Dbvc\VisualEditor\MediaManager\MediaScanService;
use Dbvc\VisualEditor\MediaManager\ScanCandidateProvider;
use Dbvc\VisualEditor\MediaManager\ScanSnapshotStore;
use Dbvc\VisualEditor\Performance\PerformanceProfiler;
use Dbvc\VisualEditor\Permissions\CapabilityManager;
use Dbvc\VisualEditor\Presentation\DescriptorSummaryBuilder;
use Dbvc\VisualEditor\Registry\EditableRegistry;
use Dbvc\VisualEditor\Resolvers\ResolverRegistry;
use Dbvc\VisualEditor\Rest\Routes;
use Dbvc\VisualEditor\Save\MutationService;
use Dbvc\VisualEditor\Save\SanitizationService;
use Dbvc\VisualEditor\Save\ValidationService;

final class Addon
{
    /**
     * @var string
     */
    private $bootstrap_file;

    /**
     * @var EditModeState
     */
    private $edit_mode;

    /**
     * @var EditableRegistry
     */
    private $registry;

    /**
     * @var ToggleNode
     */
    private $toggle_node;

    /**
     * @var AssetLoader
     */
    private $asset_loader;

    /**
     * @var HookRegistrar
     */
    private $hook_registrar;

    /**
     * @var Routes
     */
    private $routes;

    /**
     * @var ChangeJournalRecorder
     */
    private $journal;

    /**
     * @var PerformanceProfiler
     */
    private $profiler;

    /**
     * @var MediaIndexProjector
     */
    private $media_index_projector;

    /**
     * @var MediaIndexInvalidator
     */
    private $media_index_invalidator;

    /**
     * @var MediaIndexScheduler
     */
    private $media_index_scheduler;

    public function __construct($bootstrap_file)
    {
        $this->bootstrap_file = (string) $bootstrap_file;

        $this->profiler = new PerformanceProfiler();
        $active_profiler = $this->profiler->isEnabled() ? $this->profiler : null;
        $capabilities = new CapabilityManager();
        $page_context = new PageContextResolver($active_profiler);
        $runtime_guard = new FrontendRuntimeGuard();
        $loops = new LoopContextResolver(null, $active_profiler);
        $summaries = new DescriptorSummaryBuilder();
        $this->edit_mode = new EditModeState($capabilities, $page_context, $runtime_guard);
        $this->registry = new EditableRegistry($page_context, $active_profiler);
        $resolvers = new ResolverRegistry(null, $loops, null, $active_profiler);
        $validator = new ValidationService();
        $sanitizer = new SanitizationService();
        $audit = new ChangeLogger();
        $cache = new CacheInvalidator();
        $this->journal = new ChangeJournalRecorder(new ChangeJournalStore());
        $mutations = new MutationService($resolvers, $validator, $sanitizer, $audit, $cache, $summaries, $this->journal);
        $media_policy = new EligibilityPolicy($capabilities);
        $media_catalog = new AcfMediaFieldCatalog($media_policy);
        $media_scanner = new MediaScanService($media_catalog, new MediaAssignmentValueClassifier());
        $media_coordinator = new MediaScanCoordinator(
            new ScanCandidateProvider($media_policy),
            $media_scanner,
            new ScanSnapshotStore(),
            $media_catalog
        );
        $media_read_model = new MediaScanReadModel($media_coordinator, $media_scanner, $media_policy);
        $media_descriptor_bridge = new MediaFindingDescriptorBridge(
            $media_coordinator,
            $media_scanner,
            $media_policy,
            $capabilities,
            $this->registry
        );
        $media_assignment_service = new MediaAssignmentService(
            $media_descriptor_bridge,
            $mutations,
            $media_read_model
        );
        // R2-H Phase 1: the durable Media Index is populated from a completed scan and
        // kept fresh incrementally on entity changes. Read wiring (the live source flip)
        // is a later slice; this only builds/maintains the store.
        $media_index_store = new MediaIndexStore();
        $this->media_index_projector = new MediaIndexProjector($media_index_store);
        $this->media_index_invalidator = new MediaIndexInvalidator(
            $media_index_store,
            $media_scanner,
            $media_policy,
            $this->media_index_projector
        );
        // R2-H Slice 4b: the cross-user first-run build enumerates the STRUCTURAL
        // eligible set (no per-object capability — the index is site-wide and
        // capability is re-checked at read time) via its own structural pipeline.
        $structural_policy = new EligibilityPolicy($capabilities, true);
        $media_index_builder = new MediaIndexBuilder(
            $media_index_store,
            new ScanCandidateProvider($structural_policy),
            new MediaScanService(new AcfMediaFieldCatalog($structural_policy), new MediaAssignmentValueClassifier()),
            $this->media_index_projector
        );
        $this->media_index_scheduler = new MediaIndexScheduler(
            new MediaIndexReconciler($media_index_store, $this->media_index_invalidator),
            $media_index_builder
        );
        $media_index_read_model = new MediaIndexReadModel($media_index_store, $media_policy);

        $this->toggle_node = new ToggleNode($this->edit_mode, $capabilities);
        $this->asset_loader = new AssetLoader($this->bootstrap_file, $this->edit_mode, $this->registry, $page_context);
        $this->hook_registrar = new HookRegistrar($this->edit_mode, $this->registry, $page_context, $resolvers, $loops, $active_profiler);
        $this->routes = new Routes(
            $this->registry,
            $resolvers,
            $mutations,
            $this->edit_mode,
            $page_context,
            $capabilities,
            $summaries,
            $active_profiler,
            $media_coordinator,
            $media_read_model,
            $media_descriptor_bridge,
            $media_assignment_service,
            $media_index_read_model,
            $media_index_store,
            $media_policy
        );
    }

    /**
     * @return void
     */
    public function register()
    {
        $this->edit_mode->register();
        $this->toggle_node->register();
        $this->asset_loader->register();
        $this->hook_registrar->register();
        $this->routes->register();
        $this->journal->register();
        // R2-H Phase 1: keep the durable Media Index built from completed scans when the
        // Media Manager is enabled. The store's table is created lazily on first write.
        if (class_exists('\\DBVC_Visual_Editor_Addon')
            && method_exists('\\DBVC_Visual_Editor_Addon', 'is_media_manager_enabled')
            && \DBVC_Visual_Editor_Addon::is_media_manager_enabled()) {
            // Slice 4b: the structural builder is the authoritative population, so a
            // manual scan refreshes the entities it touched into the current generation
            // (onScanRefreshed) instead of rotating a fresh, capability-limited index.
            add_action('dbvc_visual_editor_media_scan_completed', [$this->media_index_projector, 'onScanRefreshed']);
            $this->media_index_invalidator->register();
            $this->media_index_scheduler->register();
        }
        add_action('wp_footer', [$this->registry, 'persistRequestSession'], 19);
        add_action('shutdown', [$this->registry, 'persistRequestSession'], 20);
        add_action('shutdown', [$this->profiler, 'flush'], 999);
    }

    /**
     * @return void
     */
    public function unregister()
    {
        $this->edit_mode->unregister();
        $this->toggle_node->unregister();
        $this->asset_loader->unregister();
        $this->hook_registrar->unregister();
        $this->routes->unregister();
        $this->journal->unregister();
        remove_action('wp_footer', [$this->registry, 'persistRequestSession'], 19);
        remove_action('shutdown', [$this->registry, 'persistRequestSession'], 20);
        remove_action('shutdown', [$this->profiler, 'flush'], 999);
    }
}
