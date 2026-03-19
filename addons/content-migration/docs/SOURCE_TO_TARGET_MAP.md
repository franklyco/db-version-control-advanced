# Source to Target Migration Map (Content Collector -> DBVC Addon)

## Bootstrap and Shared
| Source | Target | Phase |
|---|---|---|
| `_source/content-collector/content-collector.php` | `addons/content-migration/bootstrap/dbvc-cc-addon-bootstrap.php` | 0 |
| `_source/content-collector/includes/functions.php` | `addons/content-migration/shared/dbvc-cc-helpers.php` | 1 |
| `_source/content-collector/includes/class-cc-settings.php` | `addons/content-migration/settings/dbvc-cc-settings-service.php` | 1 |

## Collector
| Source | Target | Phase |
|---|---|---|
| `_source/content-collector/includes/class-cc-artifact-manager.php` | `addons/content-migration/collector/dbvc-cc-artifact-manager.php` | 1 |
| `_source/content-collector/includes/class-cc-crawler.php` | `addons/content-migration/collector/dbvc-cc-crawler-service.php` | 2 |
| `_source/content-collector/includes/class-cc-ajax.php` | `addons/content-migration/collector/dbvc-cc-ajax-controller.php` | 2 |
| `_source/content-collector/admin/js/cc-admin-script.js` | `addons/content-migration/collector/assets/dbvc-cc-crawler-admin.js` | 2 |
| `_source/content-collector/admin/views/main-page.php` | `addons/content-migration/collector/views/dbvc-cc-admin-page.php` | 2 |
| N/A (DBVC tab host) | `addons/content-migration/collector/views/tabs/dbvc-cc-tab-collect.php` | 3.5 |
| N/A (DBVC tab host) | `addons/content-migration/collector/views/tabs/dbvc-cc-tab-configure.php` | 3.5 |
| N/A (DBVC tab host) | `addons/content-migration/collector/views/tabs/dbvc-cc-tab-explore.php` | 3.5 |

## Explorer
| Source | Target | Phase |
|---|---|---|
| `_source/content-collector/includes/class-cc-explorer-service.php` | `addons/content-migration/explorer/dbvc-cc-explorer-service.php` | 2 |
| `_source/content-collector/includes/class-cc-rest-explorer.php` | `addons/content-migration/explorer/dbvc-cc-rest-controller.php` | 2 |
| N/A (DBVC Phase 3.6 REST additions: `content-context`, `scrub-policy-preview`, `scrub-policy-approval-status`, `scrub-policy-approve`) | `addons/content-migration/explorer/dbvc-cc-rest-controller.php` | 3.6 |
| `_source/content-collector/admin/js/cc-explorer.js` | `addons/content-migration/explorer/assets/dbvc-cc-explorer.js` | 2/3.7 |
| `_source/content-collector/admin/css/cc-explorer.css` | `addons/content-migration/explorer/assets/dbvc-cc-explorer.css` | 2 |
| `_source/content-collector/admin/views/explorer-page.php` | `addons/content-migration/explorer/views/dbvc-cc-explorer-page.php` | 2 |
| N/A (DBVC phase3.7 Explore -> Workbench mapping CTA) | `addons/content-migration/explorer/views/dbvc-cc-explorer-content.php` | 3.7 |
| N/A (DBVC split view) | `addons/content-migration/explorer/views/dbvc-cc-explorer-content.php` | 3.5 |

## AI Mapping
| Source | Target | Phase |
|---|---|---|
| `_source/content-collector/includes/class-cc-ai-service.php` | `addons/content-migration/ai-mapping/dbvc-cc-ai-service.php` | 3 |
| `_source/content-collector/includes/class-cc-rest-ai.php` | `addons/content-migration/ai-mapping/dbvc-cc-rest-controller.php` | 3 |

## Exports
| Source | Target | Phase |
|---|---|---|
| `_source/content-collector/includes/class-cc-export-service.php` | `addons/content-migration/exports/dbvc-cc-export-service.php` | 5 |
| `_source/content-collector/includes/class-cc-rest-export.php` | `addons/content-migration/exports/dbvc-cc-rest-controller.php` | 5 |

## New DBVC-Only Modules
| Source | Target | Phase |
|---|---|---|
| N/A | `addons/content-migration/schema-snapshot/dbvc-cc-schema-snapshot-service.php` | 1 |
| N/A | `addons/content-migration/mapping-workbench/dbvc-cc-workbench-service.php` | 3 |
| N/A (DBVC Phase 3 queue + Phase 3.7 mapping candidate/decision transport) | `addons/content-migration/mapping-workbench/dbvc-cc-workbench-rest-controller.php` | 3/3.7 |
| N/A | `addons/content-migration/mapping-workbench/views/dbvc-cc-workbench-page.php` | 3/3.7 |
| N/A | `addons/content-migration/mapping-workbench/assets/dbvc-cc-workbench.js` | 3/3.7 |
| N/A | `addons/content-migration/mapping-workbench/assets/dbvc-cc-workbench.css` | 3/3.7 |
| N/A (DBVC UX consolidation) | `addons/content-migration/docs/TEMP_UI_TAB_MAPPING.md` | 3.5 |
| N/A (DBVC UX consolidation) | `addons/content-migration/docs/PHASE3_5_TABBED_ADMIN_CONSOLIDATION.md` | 3.5 |
| N/A (DBVC deep capture/context module) | `addons/content-migration/content-context/dbvc-cc-content-context-module.php` | 3.6 |
| N/A (DBVC deep capture/context module) | `addons/content-migration/content-context/dbvc-cc-element-extractor-service.php` | 3.6 |
| N/A (DBVC deep capture/context module) | `addons/content-migration/content-context/dbvc-cc-attribute-scrub-policy-service.php` | 3.6 |
| N/A (DBVC deep capture/context module) | `addons/content-migration/content-context/dbvc-cc-attribute-scrubber-service.php` | 3.6 |
| N/A (DBVC deep capture/context module) | `addons/content-migration/content-context/dbvc-cc-section-segmenter-service.php` | 3.6 |
| N/A (DBVC deep capture/context module) | `addons/content-migration/content-context/dbvc-cc-section-typing-service.php` | 3.6 |
| N/A (DBVC deep capture/context module) | `addons/content-migration/content-context/dbvc-cc-context-bundle-service.php` | 3.6 |
| N/A (DBVC deep capture/context module) | `addons/content-migration/content-context/dbvc-cc-ingestion-package-service.php` | 3.6 |
| N/A (DBVC Phase 3.6 sidecar transport endpoint) | `addons/content-migration/explorer/dbvc-cc-rest-controller.php` | 3.6 |
| N/A (DBVC configure subtab plan) | `addons/content-migration/collector/views/tabs/configure/dbvc-cc-configure-general.php` | 3.6 |
| N/A (DBVC configure subtab plan) | `addons/content-migration/collector/views/tabs/configure/dbvc-cc-configure-advanced-collection-controls.php` | 3.6 |
| N/A (DBVC deep capture/context planning) | `addons/content-migration/docs/PHASE3_6_DEEP_CAPTURE_CONTEXT_AI.md` | 3.6 |
| N/A (DBVC explorer context contract test) | `tests/phpunit/ContentMigrationExplorerContextTest.php` | 3.6 |
| N/A (DBVC phase3.6 hardening contract tests) | `tests/phpunit/ContentMigrationPhase36HardeningTest.php` | 3.6 |
| N/A (DBVC phase3.6 deterministic rerun tests) | `tests/phpunit/ContentMigrationPhase36DeterminismTest.php` | 3.6 |
| N/A (DBVC phase3.6 leak-guard tests) | `tests/phpunit/ContentMigrationPhase36LeakGuardTest.php` | 3.6 |
| N/A (DBVC explorer context fixture snapshots) | `addons/content-migration/tests/fixtures/explorer/*.expected.json` | 3.6 |
| N/A (DBVC phase3.7 mapping catalog bridge planning) | `addons/content-migration/docs/PHASE3_7_MAPPING_CATALOG_IMPORT_BRIDGE.md` | 3.7 |
| N/A (DBVC phase3.7 mapping catalog module wiring) | `addons/content-migration/mapping-catalog/dbvc-cc-mapping-catalog-module.php` | 3.7 |
| N/A (DBVC phase3.7 target field catalog service) | `addons/content-migration/mapping-catalog/dbvc-cc-target-field-catalog-service.php` | 3.7 |
| N/A (DBVC phase3.7 target field catalog REST transport) | `addons/content-migration/mapping-catalog/dbvc-cc-target-field-catalog-rest-controller.php` | 3.7 |
| N/A (DBVC phase3.7 section-field candidate service) | `addons/content-migration/mapping-workbench/dbvc-cc-section-field-candidate-service.php` | 3.7 |
| N/A (DBVC phase3.7 mapping decision artifact service) | `addons/content-migration/mapping-workbench/dbvc-cc-mapping-decision-service.php` | 3.7 |
| N/A (DBVC phase3.7 async domain mapping rebuild queue service) | `addons/content-migration/mapping-workbench/dbvc-cc-mapping-rebuild-service.php` | 3.7 |
| N/A (DBVC phase3.7 -> phase4 handoff REST transport) | `addons/content-migration/mapping-workbench/dbvc-cc-workbench-rest-controller.php` | 3.7/4 |
| N/A (DBVC phase3.7 media mapping module wiring) | `addons/content-migration/mapping-media/dbvc-cc-mapping-media-module.php` | 3.7 |
| N/A (DBVC phase3.7 media candidate inventory service) | `addons/content-migration/mapping-media/dbvc-cc-media-candidate-service.php` | 3.7 |
| N/A (DBVC phase3.7 media decision artifact service) | `addons/content-migration/mapping-media/dbvc-cc-media-decision-service.php` | 3.7 |
| N/A (DBVC phase3.7 media mapping REST transport) | `addons/content-migration/mapping-media/dbvc-cc-media-rest-controller.php` | 3.7 |
| N/A (DBVC phase3.7 media mapping UI assets) | `addons/content-migration/mapping-workbench/assets/dbvc-cc-workbench.js` | 3.7 |
| N/A (DBVC phase3.7 media mapping UI styles) | `addons/content-migration/mapping-workbench/assets/dbvc-cc-workbench.css` | 3.7 |
| N/A (DBVC phase3.7 W0 settings/flags contract coverage) | `tests/phpunit/ContentMigrationPhase37W0SettingsTest.php` | 3.7 |
| N/A (DBVC phase3.7 W1/W4 catalog + media candidate coverage) | `tests/phpunit/ContentMigrationPhase37CatalogMediaTest.php` | 3.7 |
| N/A (DBVC phase3.7 W3/W5 mapping/media decision coverage) | `tests/phpunit/ContentMigrationPhase37MappingDecisionTest.php` | 3.7 |
| N/A (DBVC phase3.7 W8 regression gates + fixture locks) | `tests/phpunit/ContentMigrationPhase37RegressionGateTest.php` | 3.7 |
| N/A (DBVC phase3.7 W8 fixture snapshots for mapping contracts) | `addons/content-migration/tests/fixtures/mapping/*.expected.json` | 3.7 |
| N/A (DBVC phase3.7 W10 import-plan handoff bridge service) | `addons/content-migration/import-plan/dbvc-cc-import-plan-handoff-service.php` | 3.7/4 |
| N/A (DBVC phase3.7 W10 handoff runbook) | `addons/content-migration/docs/PHASE3_7_TO_PHASE4_HANDOFF_RUNBOOK.md` | 3.7 |
| N/A (DBVC phase3.7 W10 handoff transport coverage) | `tests/phpunit/ContentMigrationPhase37HandoffBridgeTest.php` | 3.7 |
| N/A (DBVC phase4 dry-run planner service) | `addons/content-migration/import-plan/dbvc-cc-import-plan-service.php` | 4 |
| N/A (DBVC phase4 dry-run planner REST transport) | `addons/content-migration/import-plan/dbvc-cc-import-plan-rest-controller.php` | 4 |
| N/A (DBVC phase4 dry-run executor service) | `addons/content-migration/import-executor/dbvc-cc-import-executor-service.php` | 4 |
| N/A (DBVC phase4 dry-run executor REST transport) | `addons/content-migration/import-executor/dbvc-cc-import-executor-rest-controller.php` | 4 |
| N/A (DBVC phase4 smoke runner script) | `addons/content-migration/tools/dbvc-cc-phase4-smoke.php` | 4 |
| N/A (DBVC phase4 implementation plan) | `addons/content-migration/docs/PHASE4_IMPORT_EXECUTION_PLAN.md` | 4 |
| N/A (DBVC phase4 dry-run executor contract coverage) | `tests/phpunit/ContentMigrationPhase4ImportExecutorTest.php` | 4 |
| N/A | `addons/content-migration/observability/dbvc-cc-log-service.php` | 5 |

## Notes
- `_source/content-collector` is reference-only and must never be loaded at runtime.
- Payload contract changes require version bump + fixture updates before merge.
- Import write operations stay behind dry-run and idempotent upsert policies.
