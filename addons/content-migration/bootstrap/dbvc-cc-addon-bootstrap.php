<?php

if (! defined('WPINC')) {
    die;
}

require_once DBVC_PLUGIN_PATH . 'addons/content-migration/shared/dbvc-cc-contracts.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/shared/dbvc-cc-helpers.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/shared/dbvc-cc-module-interface.php';
require_once DBVC_PLUGIN_PATH . 'addons/content-migration/shared/dbvc-cc-service-container.php';
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
     * @return void
     */
    public static function bootstrap()
    {
        DBVC_CC_Contracts::ensure_phase_zero_defaults();
        dbvc_cc_guard_no_source_runtime_imports('dbvc_cc_bootstrap_start');

        if (is_multisite()) {
            if (function_exists('error_log')) {
                error_log('DBVC Content Migration addon skipped: multisite is not supported.');
            }
            return;
        }

        if (get_option(DBVC_CC_Contracts::OPTION_GLOBAL_KILL_SWITCH, '0') === '1') {
            return;
        }

        if (get_option(DBVC_CC_Contracts::OPTION_ADDON_ENABLED, '1') !== '1') {
            return;
        }

        $container = new DBVC_CC_Service_Container();
        self::register_module_definitions($container);
        self::register_module_hooks($container);
        self::$container = $container;

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
