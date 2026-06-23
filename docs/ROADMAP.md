# DBVC Roadmap

This is the single repo-level planning index. Module-local plans remain with their modules and are linked here when they are active or useful.

## Active Work

| Topic | Status | Guide | Notes |
|---|---|---|---|
| Visual Editor add-on | active | `addons/visual-editor/AGENTS.md` | Current primary implementation stream. Keep detailed state in addon-local docs. |
| Admin app refactor | active | `docs/implementation/active/admin-app-refactor.md` | Long-running refactor plan plus UI architecture companion. |
| Term entity polish | needs-review | `docs/implementation/active/term-entity-polish.md` | Confirm whether this remains active before implementation. |
| Content Migration V2 | active | `addons/content-migration/docs/MIGRATION_MAPPER_V2_DOC_INDEX.md` | Module-local context pack remains the shortest resume path. |
| AI package workflow and import authoring reference | active | `docs/reference/import-authoring/README.md` | Compact package workflow exists locally; reference docs now provide the current agent-facing import contract. Remaining package QA and metrics follow-ups stay in the AI package implementation docs. |

## Proposed Work

| Topic | Status | Proposal | Notes |
|---|---|---|---|
| Cross-site entity packets | proposed | `docs/implementation/proposed/cross-site-entity-packet-guide.md` | Future transfer workflow. |
| Configuration portability tool | proposed | `docs/implementation/proposed/configuration-portability-tool-guide.md` | Future configuration transport. |
| Bricks portability drift manager | proposed | `docs/implementation/proposed/bricks-portability-drift-manager/README.md` | Proposed package/drift design. |
| Bricks reference mapping | proposed | `docs/implementation/proposed/bricks-reference-mapping-plan.md` | Proposed mapping work. |
| Media sync hydration | proposed | `docs/implementation/proposed/media-sync-hydration-guide.md` | Needs current-state review before coding. |
| User documentation library | proposed | `docs/implementation/proposed/user-documentation-library.md` | Seed for future in-plugin/user-facing docs. |
| Post-field masking expansion | proposed | `docs/implementation/proposed/post-field-masking-expansion-plan.md` | Future masking extension. |
| Third-party portability | proposed | `docs/implementation/proposed/3rd-party-portability/provider-inventory.md` | Discovery and provider inventory. |
| Entity Editor matched sync-file updates | planned | `docs/implementation/completed/entity-editor-sync-file-import-guide.md#p9-update-matched-entity-from-sync-import` | Adds an explicit confirmed `Update Matched Entity` flow for high-confidence sync-file matches without weakening create-only import safety. |

## Completed Work

| Topic | Summary | Related Docs |
|---|---|---|
| Progress summary | Recent shipped DBVC admin and import work. | `docs/implementation/completed/progress-summary.md` |
| Entity Editor | Implementation checklist, enhancements, sync-file import guide, duplicate-canonical import fixes, shared import blocker guidance, blocker resolution UI, and manual QA. | `docs/implementation/completed/entity-editor-checklist.md` |
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
