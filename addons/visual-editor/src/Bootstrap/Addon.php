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
use Dbvc\VisualEditor\MediaManager\MediaIndexJsonExporter;
use Dbvc\VisualEditor\MediaManager\MediaIndexProjector;
use Dbvc\VisualEditor\MediaManager\MediaIndexReadModel;
use Dbvc\VisualEditor\MediaManager\MediaIndexReconciler;
use Dbvc\VisualEditor\MediaManager\MediaIndexRebuildController;
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
use Dbvc\VisualEditor\Registry\ControlRegistry;
use Dbvc\VisualEditor\Registry\EditableRegistry;
use Dbvc\VisualEditor\Registry\Providers\SharedGlobalsControlProvider;
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
     * @var ControlRegistry
     */
    private $control_registry;

    /**
     * @var CapabilityManager
     */
    private $capabilities;

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

    /**
     * @var MediaIndexRebuildController
     */
    private $media_index_rebuild_controller;

    /**
     * @var MediaIndexJsonExporter
     */
    private $media_index_json_exporter;

    public function __construct($bootstrap_file)
    {
        $this->bootstrap_file = (string) $bootstrap_file;

        $this->profiler = new PerformanceProfiler();
        $active_profiler = $this->profiler->isEnabled() ? $this->profiler : null;
        $capabilities = new CapabilityManager();
        $this->capabilities = $capabilities;
        $page_context = new PageContextResolver($active_profiler);
        $runtime_guard = new FrontendRuntimeGuard();
        $loops = new LoopContextResolver(null, $active_profiler);
        $summaries = new DescriptorSummaryBuilder();
        $this->edit_mode = new EditModeState($capabilities, $page_context, $runtime_guard);
        $this->registry = new EditableRegistry($page_context, $active_profiler);
        // R3-A: the discovery-only Control Registry is instantiated unconditionally so
        // R3-C REST controllers can bind to a stable instance. Provider registration is
        // gated in register() by is_control_center_enabled(); an empty registry is safe.
        $this->control_registry = new ControlRegistry();
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
        // R2-H Slice 4b-2: topology/exclusion rebuild triggers (ACF field-group
        // save/delete, post-type/taxonomy (de)registration via a wp_loaded
        // fingerprint check, and Media-Manager exclusion-option changes) rotate a
        // fresh building generation and drive the builder; the completion step
        // atomically swaps the serving pointer and prunes the old generation.
        $this->media_index_rebuild_controller = new MediaIndexRebuildController(
            $media_index_store,
            $media_index_builder,
            $this->media_index_scheduler
        );
        // R2-H Slice 5: derived JSON mirror in the DBVC sync folder makes the index
        // backup-portable. Written at completion boundaries (rebuild swap, first-run
        // build completion, reconcile sweeps); guarded import on bootstrap hydrates
        // a fresh restore where the table is empty but the mirror exists.
        $this->media_index_json_exporter = new MediaIndexJsonExporter(
            $media_index_store,
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
            $media_policy,
            $this->control_registry
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
            // Slice 4b-2: topology/exclusion rebuild triggers.
            $this->media_index_rebuild_controller->register();
            // Slice 5: guarded restore + refresh the JSON mirror at completion
            // boundaries so the sync folder always reflects the current serving
            // generation without per-invalidator churn.
            $this->media_index_json_exporter->importIfEmpty();
            add_action('dbvc_visual_editor_media_index_build_completed', [$this->media_index_json_exporter, 'exportAll']);
            add_action('dbvc_visual_editor_media_index_reconciled', [$this->media_index_json_exporter, 'exportAll']);
        }
        // R3-B: register the Shared Globals compatibility provider on the discovery-only
        // Control Registry when the Brand Control Center kill switch is on. This is a
        // parallel surface — the existing SharedGlobalFieldsController popover route stays
        // intact — and grants no write authority; save-time capability checks still apply.
        if (class_exists('\\DBVC_Visual_Editor_Addon')
            && method_exists('\\DBVC_Visual_Editor_Addon', 'is_control_center_enabled')
            && \DBVC_Visual_Editor_Addon::is_control_center_enabled()) {
            $capabilities = $this->capabilities;
            $this->control_registry->registerProvider(new SharedGlobalsControlProvider(
                $capabilities,
                static function () {
                    if (class_exists('\\DBVC_Visual_Editor_Addon')
                        && method_exists('\\DBVC_Visual_Editor_Addon', 'get_shared_global_field_names')) {
                        return \DBVC_Visual_Editor_Addon::get_shared_global_field_names();
                    }

                    return [];
                },
                static function ($name) {
                    if (! function_exists('get_field_object')) {
                        return null;
                    }

                    return get_field_object($name, 'option', false, true);
                },
                // R4-A: option-value resolver for `buildValueSummary`. Uses
                // ACF's `get_field($name, 'option', false)` so the returned
                // value is the raw stored id list (or single id for
                // post_object), not hydrated post objects — cheap and matches
                // what the summary factory normalizes.
                static function ($name) {
                    if (! function_exists('get_field')) {
                        return null;
                    }

                    return get_field($name, 'option', false);
                }
            ));

            // Post-R3 extension point — deferred to `after_setup_theme` so a
            // theme (loaded after plugins) has a chance to attach its filter
            // callback before we invoke the filter. The deferred callback also
            // fires immediately if `after_setup_theme` has already run — that
            // happens in tests that call `refresh_runtime_registration()`
            // after WP's own init sequence, or in a mid-request rebuild.
            $registry = $this->control_registry;
            $register_external_providers = static function () use ($registry) {
                if (! class_exists('\\DBVC_Visual_Editor_Addon')
                    || ! method_exists('\\DBVC_Visual_Editor_Addon', 'is_control_center_enabled')
                    || ! \DBVC_Visual_Editor_Addon::is_control_center_enabled()) {
                    return;
                }

                /**
                 * Post-R3 extension point — external providers (e.g. a
                 * theme's VerticalControlProvider) can register additional
                 * {@see \Dbvc\VisualEditor\Registry\ControlProvider}s on the
                 * same runtime
                 * {@see \Dbvc\VisualEditor\Registry\ControlRegistry}.
                 *
                 * Fires only when both parts of the two-part kill switch are
                 * on (`is_control_center_enabled()` — see D-063). External
                 * providers inherit the discovery-only contract: their
                 * `buildDescriptor()` MAY return null (rows then surface as
                 * `status="unsupported"` and never call the open route), and
                 * any mutation continues to route through the shared
                 * `MutationService` pipeline with capability checks. Entries
                 * that are not ControlProvider instances are silently
                 * skipped; registration failures fire the observable
                 * `dbvc_visual_editor_control_registry_invalid` action.
                 *
                 * @param array<int, \Dbvc\VisualEditor\Registry\ControlProvider> $providers
                 *     External providers to register in addition to the
                 *     built-in Shared Globals compatibility provider. Default `[]`.
                 */
                $external_providers = apply_filters('dbvc_visual_editor_control_center_providers', []);
                if (is_array($external_providers)) {
                    foreach ($external_providers as $external_provider) {
                        if ($external_provider instanceof \Dbvc\VisualEditor\Registry\ControlProvider) {
                            $registry->registerProvider($external_provider);
                        }
                    }
                }
            };
            // Always defer to `after_setup_theme` rather than firing the
            // filter now. In production the plugin main file loads BEFORE
            // any theme's `functions.php`, so a filter callback the theme
            // attaches later cannot possibly have been on the hook when
            // `register()` first runs. Deferring guarantees external
            // callbacks contribute even though they attach later. A REST
            // request or a frontend page render is always a fresh request
            // where `after_setup_theme` fires after plugin bootstrap but
            // before route dispatch / asset enqueue, so the deferred
            // callback runs in time to populate the registry.
            add_action('after_setup_theme', $register_external_providers, 20);
        }
        add_action('wp_footer', [$this->registry, 'persistRequestSession'], 19);
        add_action('shutdown', [$this->registry, 'persistRequestSession'], 20);
        add_action('shutdown', [$this->profiler, 'flush'], 999);
    }

    /**
     * R3-A: expose the discovery-only Control Registry so R3-C REST controllers can
     * bind to the same instance the providers register into. Read surface only —
     * see class-level documentation on {@see ControlRegistry}.
     *
     * @return ControlRegistry
     */
    public function getControlRegistry()
    {
        return $this->control_registry;
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
