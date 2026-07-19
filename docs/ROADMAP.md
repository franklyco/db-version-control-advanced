# DBVC Roadmap

This is the single repo-level planning index. Module-local plans remain with their modules and are linked here when they are active or useful.

## Active Work

| Topic | Status | Guide | Notes |
|---|---|---|---|
| Visual Editor add-on | active | `addons/visual-editor/AGENTS.md`; `addons/visual-editor/docs/enhancements/DBVC_VISUAL_EDITOR_PHASES.md` | Current primary implementation stream. The addon-local phases guide now contains the canonical P0-P5 production backlog; P0 browser evidence now covers composite stale UI, marker-heavy idle descriptor availability, Builder-mode exclusion, query-collection save/reload, and missing-image anchor panel open. Remaining P0 fixture gaps are current rendered gallery browser save/reload and empty query-loop synthetic badge browser QA; reversible fixture probes found the current contractors vertical still renders the gallery container empty and falls back to shared populated benefits instead of producing an empty loop. P1 linked-term badge hardening now has active-build browser evidence for distinct repeated `Category Terms` badges plus runtime evidence for loop-owned category search, no-op save preservation, and reversible add/remove/restore mutation; remaining linked-term work is authenticated browser panel QA for open/owner copy/add/remove/no-reload save/reload/rendered chip update, plus either disabling term reorder or adding a dedicated order contract before claiming reorder support. Older running open-item notes were moved to `addons/visual-editor/docs/archives/DBVC_VISUAL_EDITOR_OPEN_ITEMS_CONTEXT_2026_07_07.md`; use that archive only for historical fixture/context recovery, not execution order. |
| Admin app refactor | active | `docs/implementation/active/admin-app-refactor.md` | Long-running refactor plan plus UI architecture companion. |
| Term entity polish | needs-review | `docs/implementation/active/term-entity-polish.md` | Confirm whether this remains active before implementation. |
| Content Migration V2 | active | `addons/content-migration/docs/MIGRATION_MAPPER_V2_DOC_INDEX.md` | Module-local context pack remains the shortest resume path. |
| AI package workflow and import authoring reference | active | `docs/reference/import-authoring/README.md`; `docs/implementation/proposed/ai-sample-entities-guide.md` | Compact package workflow exists locally; reference docs now provide the current agent-facing import contract. P10 tracks compact context hardening; P11 now tracks the Agent Authoring Context Catalog and Connector refresh pipeline. |
| Bricks add-on settings portability | active | `addons/bricks/docs/BRICKS_ADDON_IMPLEMENTATION_CHECKLIST.md`; `addons/bricks/docs/BRICKS_PORTABILITY_MANAGER_IMPLEMENTATION_NOTES.md` | Phase 20 has media-backed custom font/icon export/import plus add-only apply/remap/rollback support. Phase 21 adds entity-backed `bricks_templates` export/import plus add/replace apply/rollback for template posts/meta/taxonomies. Phase 22 now has initial embedded image/gallery/background/video/template-settings media hydration, nested-template remapping/blockers, preview post/term UID/slug remapping with unresolved preservation, malformed dependency descriptor import validation, stale collision guard coverage, and rollback tests. Phase 23 is in progress with first-pass query post/term include/exclude remapping, built-in link post/term remapping, skipped query/link-shape warnings, and safe login/logout dynamic post-token remapping. Phase 24 has compact row-level template reference summary counts plus apply/backup/rollback receipt counts in existing surfaces; remaining Phases 24-26 cover domain/session rollups, richer path/action details, idempotency/mixed rollback hardening, and live drill evidence without adding required per-reference user decisions. |

## Proposed Work

| Topic | Status | Proposal | Notes |
|---|---|---|---|
| Cross-site entity packets | proposed | `docs/implementation/proposed/cross-site-entity-packet-guide.md` | Future transfer workflow. |
| Configuration portability tool | proposed | `docs/implementation/proposed/configuration-portability-tool-guide.md` | Future configuration transport. |
| Bricks portability drift manager | proposed | `docs/implementation/proposed/bricks-portability-drift-manager/README.md` | Proposed package/drift design. |
| Bricks reference mapping | proposed | `docs/implementation/proposed/bricks-reference-mapping-plan.md` | Proposed mapping work. |
| Bricks add-on language refresh | completed | `addons/bricks/docs/BRICKS_ADDON_USER_FACING_LANGUAGE_AUDIT.md`; `addons/bricks/docs/BRICKS_ADDON_LANGUAGE_REFRESH_IMPLEMENTATION_GUIDE.md` | User-reviewed label/text map implemented for Bricks sync UI and Settings Transfer display copy; targeted syntax and PHPUnit verification passed. |
| Media sync hydration | proposed | `docs/implementation/proposed/media-sync-hydration-guide.md` | Needs current-state review before coding. |
| User documentation library | proposed | `docs/implementation/proposed/user-documentation-library.md` | Seed for future in-plugin/user-facing docs. |
| Post-field masking expansion | proposed | `docs/implementation/proposed/post-field-masking-expansion-plan.md` | Future masking extension. |
| Third-party portability | proposed | `docs/implementation/proposed/3rd-party-portability/provider-inventory.md` | Discovery and provider inventory. |
| WS Form Entity Editor provider mode | initial-implemented | `docs/implementation/completed/entity-editor-sync-file-import-guide.md#stage-w-ws-form-entity-editor-provider-mode` | Initial provider row, JSON edit/save, preflight, create, UID-matched whole-form update, settings merge, backend matched-update snapshots/recovery, and throwable-safe provider writes are in place. See the itemized W9 backlog below. |

### WS Form Entity Editor Provider Mode Backlog

- [W9.1 Whole-form snapshots and restore path](docs/implementation/completed/entity-editor-sync-file-import-guide.md#w91-whole-form-snapshots-and-restore-path) - initial backend snapshot implementation; restore UI remains.
- [W9.2 Provider import atomicity and rollback](docs/implementation/completed/entity-editor-sync-file-import-guide.md#w92-provider-import-atomicity-and-rollback) - backend best-effort recovery implemented; live/browser QA remains.
- [W9.3 Post-create canonicalization and stale duplicate cleanup](docs/implementation/completed/entity-editor-sync-file-import-guide.md#w93-post-create-canonicalization-and-stale-duplicate-cleanup)
- [W9.4 Ambiguous local UID detection](docs/implementation/completed/entity-editor-sync-file-import-guide.md#w94-ambiguous-local-uid-detection)
- [W9.5 Stronger WS Form payload and compatibility preflight](docs/implementation/completed/entity-editor-sync-file-import-guide.md#w95-stronger-ws-form-payload-and-compatibility-preflight)
- [W9.6 Throwable-safe provider writes](docs/implementation/completed/entity-editor-sync-file-import-guide.md#w96-throwable-safe-provider-writes) - code implemented; stub/live failure-response coverage remains.
- [W9.7 Settings merge reporting and backup](docs/implementation/completed/entity-editor-sync-file-import-guide.md#w97-settings-merge-reporting-and-backup)
- [W9.8 Live WS Form integration and browser QA coverage](docs/implementation/completed/entity-editor-sync-file-import-guide.md#w98-live-ws-form-integration-and-browser-qa-coverage)
- [W9.9 Provider UI clarity and diagnostics](docs/implementation/completed/entity-editor-sync-file-import-guide.md#w99-provider-ui-clarity-and-diagnostics)
- [W9.10 Provider service boundary for future third-party entities](docs/implementation/completed/entity-editor-sync-file-import-guide.md#w910-provider-service-boundary-for-future-third-party-entities)

## Completed Work

| Topic | Summary | Related Docs |
|---|---|---|
| Progress summary | Recent shipped DBVC admin and import work. | `docs/implementation/completed/progress-summary.md` |
| Entity Editor | Implementation checklist, raw-intake enhancements, sync-file import guide, duplicate-canonical import fixes, raw-intake duplicate JSON prevention, shared import blocker guidance, blocker resolution UI, confirmed matched sync-file updates, selected-entity incoming JSON merge, and manual QA. | `docs/implementation/completed/entity-editor-checklist.md`; `docs/implementation/completed/entity-editor-enhancements.md`; `docs/implementation/completed/entity-editor-sync-file-import-guide.md#p10-minor-fix-raw-intake-duplicate-sync-json-prevention`; `docs/implementation/completed/entity-editor-sync-file-import-guide.md#p9-update-matched-entity-from-sync-import`; `docs/implementation/completed/entity-editor-merge-incoming-json-guide.md` |
| Legacy upload immediate import | Targeted upload/import phase plan and QA notes. | `docs/implementation/completed/legacy-upload-immediate-import-plan.md` |
| Import identity hardening | Current matching contract plus historical hardening note. | `docs/reference/import-identity-matching.md` |
| Meta masking | Current reference plus completed plan. | `docs/reference/meta-masking.md` |
| Proposal diff minor update | Completed implementation guide retained for behavior context. | `docs/implementation/completed/proposal-diff-system-minor-update-guide.md` |

## Archived Or Superseded Work

| Topic | Archive Path | Replacement Doc | Notes |
|---|---|---|---|
| Old root roadmap and planning notes | `docs/archives/root-planning/` | `docs/roadmap.md` | Preserved as historical context. |
| Root handoffs | `docs/archives/root-handoffs/` | `docs/README.md` | Includes old root handoff and previous root `AGENTS.md` snapshot. |
| Entity Editor handoff/review | `docs/archives/entity-editor/` | `docs/reference/entity-editor-usage.md` | Implementation is complete enough that handoffs are historical. |
| Proposal diff V2 planning | `docs/archives/proposal-diff-v2/` | `docs/implementation/completed/proposal-diff-system-minor-update-guide.md` | Historical audit and rollout docs. |
| Bricks assets planning | `docs/archives/bricks-assets/` | `docs/architecture/bricks-assets-engine-contract-draft.md` | Discovery/handoff material. |
| Bricks addon decisions | `docs/archives/bricks-addon/` | `addons/bricks/docs/BRICKS_ADDON_PLAN.md` | Historical addon handoff and recommendation. |
| Content migration workbench handoff | `docs/archives/content-migration-workbench-handoff/` | `addons/content-migration/docs/MIGRATION_MAPPER_V2_DOC_INDEX.md` | Historical standalone handoff pack. |
| Visual Editor running open-item notes | `addons/visual-editor/docs/archives/DBVC_VISUAL_EDITOR_OPEN_ITEMS_CONTEXT_2026_07_07.md` | `addons/visual-editor/docs/enhancements/DBVC_VISUAL_EDITOR_PHASES.md` | Superseded thread-style backlog preserved after consolidation into the P0-P5 production backlog. |
