# DBVC Content Migration Add-on Facet

Load this facet for Content Migration, Content Collector, Migration Mapper V2, crawl/explorer workflows, migration packages, recovery fixtures, or import-run execution.

## Runtime Boundary

| Record | Boundary |
|---|---|
| `cli.content_migration.runs.inspect` | Read-only latest-run listing and exact current/historical run summaries |
| `addon.content_migration.runtime_guard` | Active loaded add-on workflow; mixed read/write safety |
| `source.content_collector.explorer` | Retained source reference, not a separately loaded runtime |
| `source.content_collector.ai` | Retained source reference, not a callable AI provider contract |
| `source.content_collector.export` | Retained source reference, not a separately loaded export runtime |

The active add-on reuses and extends retained Content Collector concepts. Do not infer that every file under `_source/content-collector/` is independently loaded.

## Safe Progression

1. Confirm the add-on setting, current V2 working state, and target site.
2. Inspect existing runs with `wp dbvc content-migration runs list` and one exact `show` before considering readiness or package work.
3. Inspect crawl, mapping, and package artifacts before any import execution.
4. Review import-run actions and media handling.
5. Back up the target WordPress data and uploads involved.
6. Execute only with explicit write authority.
7. Verify receipts, run-state tables, and rendered content afterward.

## Load Next

- Add-on entry point: [`addons/content-migration/README.md`](../../../addons/content-migration/README.md)
- Current V2 router: [`MIGRATION_MAPPER_V2_DOC_INDEX.md`](../../../addons/content-migration/docs/MIGRATION_MAPPER_V2_DOC_INDEX.md)
- Working state: [`MIGRATION_MAPPER_V2_WORKING_STATE.md`](../../../addons/content-migration/docs/MIGRATION_MAPPER_V2_WORKING_STATE.md)
- Runtime bootstrap: [`dbvc-cc-addon-bootstrap.php`](../../../addons/content-migration/bootstrap/dbvc-cc-addon-bootstrap.php)
- Read-only run CLI: [`commands/class-content-migration-cli.php`](../../../commands/class-content-migration-cli.php)
- Current record details: [`manifest.json`](../manifest.json)
