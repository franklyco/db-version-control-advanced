# DBVC Content Migration Addon Phase Plan

## 1) Scope and Constraints
- Single-site only. No multisite scope.
- No Gutenberg/block template suggestion features.
- No legacy standalone plugin compatibility requirements.
- Implementation must be modular inside a dedicated addon folder, not monolith classes/files.
- Content Collector source folder (`./content-collector`) is treated as migration source code only.

## 1.1) Required Governance Docs
- `CONTENT_COLLECTOR_ADDON_MANIFEST.json`
- `GUARDRAILS.md`
- `HANDOFF.md`

## 2) Target Addon Structure (DBVC)
Use a dedicated addon root and split features by module:

`addons/content-migration/`
- `bootstrap/`
- `settings/`
- `schema-snapshot/`
- `collector/`
- `explorer/`
- `ai-mapping/`
- `mapping-workbench/`
- `import-plan/`
- `import-executor/`
- `exports/`
- `observability/`
- `shared/`
- `tests/fixtures/`

## 3) Phase Sequence

### Phase 0: Addon Skeleton and Contracts
Goals:
- Create addon folder/module boundaries.
- Register addon bootstrap and service container wiring.
- Add config and contract docs references.

Deliverables:
- Addon bootstrap loaded by DBVC.
- Empty module service classes/interfaces.
- Contract constants for option keys, transient prefixes, and storage roots.

Acceptance Criteria:
- Addon boots cleanly in WP admin with no fatal errors.
- Module registration can instantiate service stubs.
- Contract constants are centralized and reused.

### Phase 1: Foundation (Settings + Storage + Schema Snapshot)
Goals:
- Port settings schema and validation.
- Port deterministic filesystem storage behavior.
- Add schema snapshot capability for CPT/taxonomy/terms/users/media/fields.

Deliverables:
- Settings service with keys from source manifest.
- Artifact manager equivalent (pathing, security files, index, redirects, logs, dev mode copies).
- Schema snapshot runner and persisted snapshot artifact.

Acceptance Criteria:
- Option storage works with validated defaults.
- Crawled artifact paths resolve deterministically.
- `.cc-index.json`, `redirect-map.json`, and `_logs/events.ndjson` are generated correctly.
- Schema snapshot exports exact object metadata and constraints for current site.

### Phase 2: Collector and Explorer V1
Goals:
- Port crawl pipeline and AJAX handlers.
- Port Explorer REST routes and tree/node/content/audit behavior.
- Port Explorer UI with search, diff, node actions, and audit trail.

Deliverables:
- Sitemap URL ingestion + per-page crawl processing.
- Explorer endpoints:
  - `/explorer/domains`
  - `/explorer/tree`
  - `/explorer/node/children`
  - `/explorer/node`
  - `/explorer/content`
  - `/explorer/node/audit`
- Explorer admin screen with Cytoscape graph, inspector, raw/sanitized diff, and audit module.

Acceptance Criteria:
- Crawl artifacts are written and visible in Explorer.
- Explorer supports expand/collapse, filtering, search highlight, and inspector links.
- Diff and audit payloads render without runtime errors.
- Explorer performance controls (depth/max nodes/cache) function as expected.

### Phase 3: AI Mapping + Mapping Workbench
Goals:
- Port AI rerun pipeline and deterministic fallback.
- Implement smart mapping suggestions using schema snapshot + content signals.
- Add human review queue for low-confidence/conflict cases.

Deliverables:
- AI routes:
  - `/ai/rerun`
  - `/ai/rerun-branch`
  - `/ai/status`
- AI service with:
  - CPT suggestion
  - taxonomy/category suggestion
  - custom field extraction suggestions
  - media role inference
  - user/author suggestion
  - fallback mode when AI unavailable/fails
- Mapping workbench UI for approval/rejection/edit of suggestions.

Acceptance Criteria:
- AI status lifecycle works (`queued`, `processing`, `completed`, `failed`, `fallback`).
- Suggestions are persisted with confidence + rationale.
- Rules-only fallback can complete mappings when AI is unavailable.
- Review queue isolates only uncertain/conflicting items.

### Phase 3.6: Deep Capture + Context Packaging + Advanced Section Typing
Goals:
- Upgrade crawl artifacts to deep, element-level structured capture.
- Build deterministic context bundles for richer pre-import inference.
- Add advanced AI section-type narrowing with deterministic fallback.
- Add configurable attribute scrub controls with deterministic policy enforcement.

Deliverables:
- New artifact set for each page:
  - `*.elements.v2.json`
  - `*.sections.v2.json`
  - `*.context-bundle.v2.json`
  - `*.section-typing.v2.json`
  - `*.ingestion-package.v2.json`
  - `*.attribute-scrub-report.v2.json`
- Module boundary for context processing (extraction, segmentation, packaging).
- Configure defaults + Collect per-run overrides for deep/context settings.
- Configure subtab architecture: `General` + `Advanced Collection Controls`.
- Explorer visibility for deep artifacts and section typing output.

Acceptance Criteria:
- Deep element capture is deterministic and traceable to section/group outputs.
- Context bundles provide stable, AI-ready inputs with source trace references.
- AI section typing supports confidence/rationale and emits `fallback` mode when needed.
- Scrub policy output is deterministic, auditable, and never auto-applies AI suggestions.
- New payload contracts are fixture-locked with version bump documentation.

### Phase 4: Import Planning and Execution
Goals:
- Build dry-run import planner.
- Build idempotent executor with collision policy enforcement.
- Support rerun/retry and checkpoint resume.

Deliverables:
- Import-plan generator with:
  - write-order dependencies
  - validation errors/warnings
  - collision outcomes
  - permalink redirect outcomes
- Import executor with:
  - deterministic external IDs
  - upsert behavior
  - per-item status logs
  - retry for failed records

Acceptance Criteria:
- Dry-run is required before commit import.
- Import writes are idempotent on rerun (no duplicate object creation).
- Slug/tax/user/media conflicts follow configured policy.
- Failed item retries do not break successful items.

### Phase 5: Export, QA, and Hardening
Goals:
- Finalize export package outputs.
- Add post-import QA checks and reporting.
- Complete observability and regression fixture coverage.

Deliverables:
- Export endpoints and package generation (`json|yaml|md`, zip + manifest + redirects + logs).
- QA validators:
  - internal link checks
  - media resolution checks
  - required field/taxonomy checks
- Rollup reports for migration quality and unresolved issues.

Acceptance Criteria:
- Export manifest includes AI status flags and checksums.
- QA reports clearly identify blocking vs non-blocking issues.
- Fixtures validate stable payload schemas across releases.

## 4) Cross-Cutting Must-Haves
- Versioned schema snapshots and mapping specs per run.
- Structured observability logs by stage/object/failure code.
- PII flagging + redaction-rule enforcement.
- Deterministic non-AI path always available.
- Strict capability and nonce enforcement.
- Path-guard protections and directory hardening.
- Guardrail compliance from `GUARDRAILS.md` enforced and reported in each phase PR.

## 5) File-by-File Source Mapping (Content Collector -> DBVC Addon)

### Bootstrap and Settings
- `content-collector/content-collector.php` -> `addons/content-migration/bootstrap/addon-bootstrap.php`
- `content-collector/includes/class-cc-settings.php` -> `addons/content-migration/settings/settings-service.php`
- `content-collector/includes/functions.php` -> `addons/content-migration/shared/helpers.php`

### Storage and Crawl
- `content-collector/includes/class-cc-artifact-manager.php` -> `addons/content-migration/collector/artifact-manager.php`
- `content-collector/includes/class-cc-crawler.php` -> `addons/content-migration/collector/crawler-service.php`
- `content-collector/includes/class-cc-ajax.php` -> `addons/content-migration/collector/ajax-controller.php`
- `content-collector/admin/js/cc-admin-script.js` -> `addons/content-migration/collector/assets/crawler-admin.js`
- `content-collector/admin/views/main-page.php` -> `addons/content-migration/collector/views/admin-page.php`

### Explorer
- `content-collector/includes/class-cc-explorer-service.php` -> `addons/content-migration/explorer/explorer-service.php`
- `content-collector/includes/class-cc-rest-explorer.php` -> `addons/content-migration/explorer/rest-controller.php`
- `content-collector/admin/js/cc-explorer.js` -> `addons/content-migration/explorer/assets/explorer.js`
- `content-collector/admin/css/cc-explorer.css` -> `addons/content-migration/explorer/assets/explorer.css`
- `content-collector/admin/views/explorer-page.php` -> `addons/content-migration/explorer/views/explorer-page.php`

### AI Mapping
- `content-collector/includes/class-cc-ai-service.php` -> `addons/content-migration/ai-mapping/ai-service.php`
- `content-collector/includes/class-cc-rest-ai.php` -> `addons/content-migration/ai-mapping/rest-controller.php`

### Exports
- `content-collector/includes/class-cc-export-service.php` -> `addons/content-migration/exports/export-service.php`
- `content-collector/includes/class-cc-rest-export.php` -> `addons/content-migration/exports/rest-controller.php`

### New DBVC-Only Modules (No direct source equivalent)
- `addons/content-migration/schema-snapshot/snapshot-service.php`
- `addons/content-migration/mapping-workbench/workbench-service.php`
- `addons/content-migration/mapping-workbench/workbench-rest-controller.php`
- `addons/content-migration/import-plan/plan-service.php`
- `addons/content-migration/import-plan/plan-rest-controller.php`
- `addons/content-migration/import-executor/executor-service.php`
- `addons/content-migration/import-executor/executor-rest-controller.php`
- `addons/content-migration/observability/log-service.php`

## 6) Workflow Coverage Matrix

### Must-Have Workflows
- Schema snapshot generation and versioning.
- Crawl collection and deterministic artifact persistence.
- Explorer inspection and node-level audit visibility.
- AI-assisted mapping with fallback.
- Human review and conflict resolution.
- Dry-run import simulation.
- Commit import with idempotent execution.
- Export package generation and download.
- Post-import QA and report generation.

### Smart Mapping Workflows
- CPT inference from headings/content/URL semantics.
- Taxonomy term matching via exact + semantic ranking.
- Custom field extraction from structured section groups.
- Media role suggestion (featured/gallery/icon/background).
- Author/user suggestion from byline/context.
- Internal link rewrite mapping (`old -> planned permalink`).
- Duplicate clustering for review.
- Confidence thresholds with auto-accept and review queues.

## 7) Done Criteria for Full Addon
- DBVC addon executes crawl -> explore -> map -> dry-run -> import -> export end-to-end.
- All core route/API contracts are implemented in addon modules.
- Deterministic behavior and fallback modes are preserved.
- Fixture-based parity checks pass for key payload contracts.
