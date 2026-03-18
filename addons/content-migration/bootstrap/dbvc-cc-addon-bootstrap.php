<?php

if (! defined('WPINC')) {
    die;
}

require_once DBVC_PLUGIN_PATH . 'addons/content-migration/shared/dbvc-cc-contracts.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/shared/dbvc-cc-helpers.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/shared/dbvc-cc-module-interface.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/shared/dbvc-cc-service-container.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/v2/shared/dbvc-cc-v2-contracts.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/v2/admin/dbvc-cc-v2-configure-addon-settings.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/v2/admin/dbvc-cc-v2-app-loader.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/v2/admin/dbvc-cc-v2-admin-menu-service.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/v2/bootstrap/dbvc-cc-v2-runtime-registrar.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/v2/bootstrap/dbvc-cc-v2-addon.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/settings/dbvc-cc-settings-service.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/settings/dbvc-cc-settings-module.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/collector/dbvc-cc-artifact-manager.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/content-context/dbvc-cc-attribute-scrub-policy-service.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/content-context/dbvc-cc-attribute-scrubber-service.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/content-context/dbvc-cc-element-extractor-service.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/content-context/dbvc-cc-section-segmenter-service.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/content-context/dbvc-cc-section-typing-service.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/content-context/dbvc-cc-context-bundle-service.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/content-context/dbvc-cc-ingestion-package-service.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/content-context/dbvc-cc-content-context-module.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/collector/dbvc-cc-crawler-service.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/collector/dbvc-cc-ajax-controller.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/collector/dbvc-cc-admin-controller.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/schema-snapshot/dbvc-cc-schema-snapshot-module.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/schema-snapshot/dbvc-cc-schema-snapshot-service.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/collector/dbvc-cc-collector-module.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/explorer/dbvc-cc-explorer-service.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/explorer/dbvc-cc-rest-controller.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/explorer/dbvc-cc-explorer-module.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/ai-mapping/dbvc-cc-ai-service.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/ai-mapping/dbvc-cc-rest-controller.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/ai-mapping/dbvc-cc-ai-mapping-module.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/mapping-workbench/dbvc-cc-workbench-service.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/mapping-workbench/dbvc-cc-section-field-candidate-service.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/mapping-workbench/dbvc-cc-mapping-decision-service.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/mapping-workbench/dbvc-cc-mapping-rebuild-service.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/mapping-workbench/dbvc-cc-workbench-rest-controller.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/mapping-workbench/dbvc-cc-mapping-workbench-module.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/mapping-catalog/dbvc-cc-target-field-catalog-service.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/mapping-catalog/dbvc-cc-target-field-catalog-rest-controller.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/mapping-catalog/dbvc-cc-mapping-catalog-module.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/mapping-media/dbvc-cc-media-candidate-service.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/mapping-media/dbvc-cc-media-decision-service.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/mapping-media/dbvc-cc-media-rest-controller.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/mapping-media/dbvc-cc-mapping-media-module.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/import-plan/dbvc-cc-import-plan-handoff-service.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/import-plan/dbvc-cc-import-plan-service.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/import-plan/dbvc-cc-import-plan-rest-controller.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/import-plan/dbvc-cc-import-plan-module.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/import-executor/dbvc-cc-import-run-store.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/import-executor/dbvc-cc-import-executor-service.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/import-executor/dbvc-cc-import-executor-rest-controller.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/import-executor/dbvc-cc-import-executor-module.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/exports/dbvc-cc-exports-module.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/observability/dbvc-cc-observability-module.php';

final class DBVC_CC_Addon_Bootstrap
{
    /**
     * @var DBVC_CC_Service_Container|null
     */
    private static $container = null;

    /**
     * @var string
     */
    private static $active_runtime = '';

    /**
     * @return void
     */
    public static function bootstrap()
    {
        DBVC_CC_Contracts::ensure_phase_zero_defaults();
        DBVC_CC_V2_Contracts::ensure_defaults();
        dbvc_cc_guard_no_source_runtime_imports('dbvc_cc_bootstrap_start');

        if (is_multisite()) {
            if (function_exists('error_log')) {
                error_log('DBVC Content Migration addon skipped: multisite is not supported.');
            }
            return;
        }

        if (get_option(DBVC_CC_Contracts::OPTION_GLOBAL_KILL_SWITCH, '0') === '1') {
            self::deactivate_v1_runtime();
            return;
        }

        DBVC_CC_V2_Addon::bootstrap();

        dbvc_cc_guard_no_source_runtime_imports('dbvc_cc_bootstrap_complete');
    }

    /**
     * @return DBVC_CC_Service_Container|null
     */
    public static function get_container()
    {
        return self::$container;
    }

    /**
     * @return string
     */
    public static function get_active_runtime()
    {
        return self::$active_runtime;
    }

    /**
     * @return void
     */
    public static function bootstrap_v1_runtime()
    {
        if (self::$container instanceof DBVC_CC_Service_Container) {
            self::$active_runtime = DBVC_CC_V2_Contracts::RUNTIME_V1;
            return;
        }

        $container = new DBVC_CC_Service_Container();
        self::register_module_definitions($container);
        self::register_module_hooks($container);
        self::$container = $container;
        self::$active_runtime = DBVC_CC_V2_Contracts::RUNTIME_V1;
    }

    /**
     * @return void
     */
    public static function deactivate_v1_runtime()
    {
        if (self::$active_runtime !== DBVC_CC_V2_Contracts::RUNTIME_V1) {
            self::clear_scheduled_hooks();
            self::$container = null;
            return;
        }

        remove_action('admin_init', [DBVC_CC_Settings_Service::class, 'register_settings']);
        remove_action('admin_init', [DBVC_CC_Schema_Snapshot_Service::class, 'maybe_generate_initial_snapshot'], 20);
        remove_action(DBVC_CC_Contracts::ACTION_RUN_SCHEMA_SNAPSHOT, [DBVC_CC_Schema_Snapshot_Service::class, 'generate_snapshot']);
        remove_action('init', [DBVC_CC_Artifact_Manager::class, 'ensure_storage_roots'], 15);

        $admin_controller = DBVC_CC_Admin_Controller::get_instance();
        remove_action('admin_menu', [$admin_controller, 'add_admin_menu'], 90);
        remove_action('admin_enqueue_scripts', [$admin_controller, 'enqueue_scripts']);

        $ajax_controller = DBVC_CC_Ajax_Controller::get_instance();
        remove_action('wp_ajax_' . DBVC_CC_Contracts::AJAX_ACTION_GET_URLS_FROM_SITEMAP, [$ajax_controller, 'get_urls_from_sitemap']);
        remove_action('wp_ajax_' . DBVC_CC_Contracts::AJAX_ACTION_PROCESS_SINGLE_URL, [$ajax_controller, 'process_single_url']);
        remove_action('wp_ajax_' . DBVC_CC_Contracts::AJAX_ACTION_DBVC_CC_TRIGGER_DOMAIN_AI_REFRESH, [$ajax_controller, 'dbvc_cc_trigger_domain_ai_refresh']);

        $explorer_rest = DBVC_CC_Explorer_REST_Controller::get_instance();
        remove_action('rest_api_init', [$explorer_rest, 'register_routes']);

        $ai_service = DBVC_CC_AI_Service::get_instance();
        remove_action(DBVC_CC_Contracts::CRON_HOOK_AI_PROCESS_JOB, [$ai_service, 'process_job'], 10);

        $ai_rest = DBVC_CC_AI_REST_Controller::get_instance();
        remove_action('rest_api_init', [$ai_rest, 'register_routes']);

        $workbench_rest = DBVC_CC_Workbench_REST_Controller::get_instance();
        remove_action('rest_api_init', [$workbench_rest, 'register_routes']);

        $catalog_rest = DBVC_CC_Target_Field_Catalog_REST_Controller::get_instance();
        remove_action('rest_api_init', [$catalog_rest, 'register_routes']);

        $media_rest = DBVC_CC_Media_REST_Controller::get_instance();
        remove_action('rest_api_init', [$media_rest, 'register_routes']);

        $import_plan_rest = DBVC_CC_Import_Plan_REST_Controller::get_instance();
        remove_action('rest_api_init', [$import_plan_rest, 'register_routes']);

        $import_executor_rest = DBVC_CC_Import_Executor_REST_Controller::get_instance();
        remove_action('rest_api_init', [$import_executor_rest, 'register_routes']);

        $mapping_rebuild = DBVC_CC_Mapping_Rebuild_Service::get_instance();
        remove_action(DBVC_CC_Contracts::CRON_HOOK_MAPPING_REBUILD_BATCH, [$mapping_rebuild, 'dbvc_cc_process_rebuild_batch_event'], 10);

        self::clear_scheduled_hooks();
        self::$container = null;
        self::$active_runtime = '';
    }

    /**
     * @return void
     */
    public static function clear_scheduled_hooks()
    {
        if (! function_exists('wp_clear_scheduled_hook')) {
            return;
        }

        wp_clear_scheduled_hook(DBVC_CC_Contracts::CRON_HOOK_AI_PROCESS_JOB);
        wp_clear_scheduled_hook(DBVC_CC_Contracts::CRON_HOOK_MAPPING_REBUILD_BATCH);
    }

    /**
     * @param DBVC_CC_Service_Container $container
     * @return void
     */
    private static function register_module_definitions(DBVC_CC_Service_Container $container)
    {
        $container->set(DBVC_CC_Settings_Module::SERVICE_ID, static function () {
            return new DBVC_CC_Settings_Module();
        });
        $container->set(DBVC_CC_Schema_Snapshot_Module::SERVICE_ID, static function () {
            return new DBVC_CC_Schema_Snapshot_Module();
        });
        $container->set(DBVC_CC_Collector_Module::SERVICE_ID, static function () {
            return new DBVC_CC_Collector_Module();
        });
        $container->set(DBVC_CC_Content_Context_Module::SERVICE_ID, static function () {
            return new DBVC_CC_Content_Context_Module();
        });
        $container->set(DBVC_CC_Explorer_Module::SERVICE_ID, static function () {
            return new DBVC_CC_Explorer_Module();
        });
        $container->set(DBVC_CC_AI_Mapping_Module::SERVICE_ID, static function () {
            return new DBVC_CC_AI_Mapping_Module();
        });
        $container->set(DBVC_CC_Mapping_Workbench_Module::SERVICE_ID, static function () {
            return new DBVC_CC_Mapping_Workbench_Module();
        });
        $container->set(DBVC_CC_Mapping_Catalog_Module::SERVICE_ID, static function () {
            return new DBVC_CC_Mapping_Catalog_Module();
        });
        $container->set(DBVC_CC_Mapping_Media_Module::SERVICE_ID, static function () {
            return new DBVC_CC_Mapping_Media_Module();
        });
        $container->set(DBVC_CC_Import_Plan_Module::SERVICE_ID, static function () {
            return new DBVC_CC_Import_Plan_Module();
        });
        $container->set(DBVC_CC_Import_Executor_Module::SERVICE_ID, static function () {
            return new DBVC_CC_Import_Executor_Module();
        });
        $container->set(DBVC_CC_Exports_Module::SERVICE_ID, static function () {
            return new DBVC_CC_Exports_Module();
        });
        $container->set(DBVC_CC_Observability_Module::SERVICE_ID, static function () {
            return new DBVC_CC_Observability_Module();
        });
    }

    /**
     * @param DBVC_CC_Service_Container $container
     * @return void
     */
    private static function register_module_hooks(DBVC_CC_Service_Container $container)
    {
        foreach (self::get_module_service_ids() as $service_id) {
            $module = $container->get($service_id);
            if (! ($module instanceof DBVC_CC_Module_Interface)) {
                continue;
            }
            $module->register($container);
        }
    }

    /**
     * @return array<int, string>
     */
    private static function get_module_service_ids()
    {
        return [
            DBVC_CC_Settings_Module::SERVICE_ID,
            DBVC_CC_Schema_Snapshot_Module::SERVICE_ID,
            DBVC_CC_Collector_Module::SERVICE_ID,
            DBVC_CC_Content_Context_Module::SERVICE_ID,
            DBVC_CC_Explorer_Module::SERVICE_ID,
            DBVC_CC_AI_Mapping_Module::SERVICE_ID,
            DBVC_CC_Mapping_Workbench_Module::SERVICE_ID,
            DBVC_CC_Mapping_Catalog_Module::SERVICE_ID,
            DBVC_CC_Mapping_Media_Module::SERVICE_ID,
            DBVC_CC_Import_Plan_Module::SERVICE_ID,
            DBVC_CC_Import_Executor_Module::SERVICE_ID,
            DBVC_CC_Exports_Module::SERVICE_ID,
            DBVC_CC_Observability_Module::SERVICE_ID,
        ];
    }
}
