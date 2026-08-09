# DBVC Agent Reference Library Implementation Plan

Date: 2026-08-04  
Last updated: 2026-08-06  
Status: Phases 1 through 7 complete; Phase 8 optional agent recipes are the next approval boundary  
Scope authority: This document defines the implementation sequence for an opt-in agent reference layer under `docs/agents/`. It does not authorize runtime changes, live-site changes, or capability research beyond the explicit phase gates below.

## LocalWP Merge Addendum — 2026-08-06

The completed library and its administrator Capability Landscape were merged into the active LocalWP plugin checkout at HEAD `78a06ad6429574bc1ac88158ec7c037b257dd3a6` without replacing unrelated dirty work.

The current-checkout reconciliation supersedes the original detached-worktree counts in the historical phase notes below:

- 47 curated records;
- 393 strictly owned discovery surfaces;
- 13 WP-CLI leaf commands;
- 126 REST registrations;
- zero unmapped surfaces.

The merge also expanded CLI discovery across all PHP files under `commands/`, reclassified the source-loaded Visual Editor and Content Migration add-ons, and added reviewed records for media hydration, configuration portability, and AI package intake. The canonical implementation path is this file; the opt-in operational entrypoint remains `docs/agents/README.md`.

Same-checkout authenticated runtime verification remains pending. Static source presence and manifest ownership do not authorize write operations.

## 1. Objective

Create a compact, agent-facing reference layer that helps an agent discover the relevant DBVC capability without loading the entire documentation tree.

The reference layer will:

- live inside the existing `docs/` tree;
- be loaded only when a task calls for DBVC capability discovery or a matching category/tag;
- summarize and route to existing code and documentation rather than duplicate full guides;
- distinguish current runtime capabilities from planned, source-reference, deprecated, or checkout-absent work;
- make combinations such as `operation:import` + `surface:cli` directly discoverable;
- expose safety, authorization, storage, rollback, and verification context before an agent invokes a write path;
- maintain a generated index that can be rebuilt and checked for drift.

This is not a second user-documentation library, a replacement for existing handoffs, or a new in-plugin documentation UI.

## 2. Design Principles

### 2.1 Opt-in placement

The library will live at `docs/agents/` and remain outside default startup instructions. An agent should load it when:

- the task explicitly references the DBVC agent library;
- the agent needs to discover whether DBVC already supports an operation;
- the task involves adding or changing a CLI command, REST route, setting, hook, add-on, import/export path, or data contract;
- the task asks for automation, client-site rollout, capability comparison, or gap analysis.

The library should not be promoted into every task's required first-read list.

### 2.2 Routing layer, not duplicated documentation

Each capability record will contain a concise operational summary and exact pointers to authoritative code, tests, and existing docs. Long-form explanation remains in the existing documents.

The agent reference may clarify status or safety boundaries that are missing from an older document, but it should not copy whole tutorials, phase plans, or handoffs.

### 2.3 Code truth and documentation truth remain distinct

The system will maintain three explicit layers:

1. **Observed code surfaces**: mechanically discoverable registrations and symbols such as CLI commands, REST routes, hooks, settings keys, admin handlers, and bootstrap includes.
2. **Curated capability manifest**: reviewed records explaining what those surfaces mean, their status, risk, dependencies, and relationships.
3. **Generated discovery index**: agent-friendly category and tag views built from the curated manifest.

Generated discovery can detect that a surface exists. It must not automatically decide that a capability is safe, supported, production-ready, or appropriate for autonomous use.

### 2.4 Status must be explicit

Every record must use one of these initial statuses:

- `active`: loaded and currently supported in this checkout;
- `experimental`: loaded or callable, but not yet a stable supported contract;
- `planned`: documented future work with no current callable surface;
- `source_reference`: code or artifacts retained for research/migration but deliberately excluded from runtime;
- `deprecated`: retained for compatibility but not recommended for new work;
- `absent_current_checkout`: known from another DBVC checkout or historical context but not present here;
- `unknown_requires_verification`: discovered but not yet verified enough to classify.

Historical documents must not be used to promote a record to `active` without current code or runtime evidence.

### 2.5 Safety is part of discovery

An agent should be able to determine the consequence of a capability before following its implementation path. Every callable or operational record must identify:

- read-only versus write behavior;
- filesystem, WordPress database, remote-system, or destructive effects;
- required capability, nonce, authentication, confirmation, or environment;
- dry-run or preview support;
- idempotency expectations;
- backup, restore, or rollback support;
- whether live runtime verification is required before use.

## 3. Planned Folder Shape

Only the implementation plan is being created now. The remaining structure is created in later approved phases.

```text
docs/
├── DBVC_AGENT_REFERENCE_IMPLEMENTATION_PLAN.md
└── agents/
    ├── README.md
    ├── manifest.json
    ├── manifest.schema.json
    ├── taxonomy.md
    ├── facets/
    │   ├── core-import-export.md
    │   ├── cli-and-automation.md
    │   ├── proposals-and-review.md
    │   ├── media-and-resolver.md
    │   ├── identity-snapshots-and-storage.md
    │   ├── entity-editor.md
    │   ├── settings-and-extension-points.md
    │   ├── bricks-addon.md
    │   └── staged-planned-and-absent.md
    └── generated/
        ├── index-by-category.md
        ├── index-by-tag.md
        ├── index-by-surface.md
        ├── index-by-operation.md
        ├── index-by-risk.md
        ├── index-by-alias.md
        ├── index-by-command.md
        └── discovery-snapshot.json
```

This structure is intentionally shallow:

- `README.md` is the agent entrypoint and contains the compact generated index summary.
- `manifest.json` is the reviewed registry.
- `facets/` provides small context packets for major capability families.
- `generated/` contains replaceable derivative indexes and the latest source-discovery snapshot.
- Existing docs remain in their current locations and are linked from records.

If the initial manifest shows that fewer facet files are sufficient, files should be combined rather than creating empty categories.

## 4. Discovery Taxonomy

The taxonomy must support compound lookup instead of forcing an agent to know DBVC's internal class names.

### 4.1 Primary category

Each record has one primary category:

- `import_export`
- `cli_automation`
- `proposal_review`
- `media_resolver`
- `identity_entities`
- `snapshots_backups`
- `entity_editor`
- `settings_configuration`
- `api_extensions`
- `addon_bricks`
- `addon_content_migration`
- `observability`
- `internal_foundation`

Categories may be revised during initial research, but category changes must preserve stable record IDs and aliases.

### 4.2 Faceted tags

Tags use namespaced values so their meaning remains clear.

| Tag namespace | Initial values/examples | Purpose |
|---|---|---|
| `surface:` | `cli`, `rest`, `admin`, `php`, `hook`, `ajax`, `admin_post`, `cron`, `filesystem`, `database` | Where the capability is exposed |
| `operation:` | `inspect`, `list`, `preview`, `validate`, `export`, `import`, `upload`, `route`, `compare`, `apply`, `delete`, `restore`, `configure`, `diagnose`, `generate` | What an agent or operator can do |
| `object:` | `post`, `term`, `media`, `menu`, `option`, `acf_options`, `bricks_template`, `package`, `proposal`, `snapshot` | Data or artifact acted on |
| `scope:` | `core`, `addon:bricks`, `addon:content_migration`, `source_reference` | Ownership/runtime scope |
| `risk:` | `read_only`, `filesystem_write`, `wordpress_write`, `remote_write`, `destructive`, `requires_backup` | Material effect |
| `workflow:` | `client_onboarding`, `site_migration`, `proposal_review`, `deployment`, `recovery`, `development` | Common task context |
| `status:` | Values from Section 2.4 | Current authority state |

Examples:

- An agent searching for import CLI functionality can match `operation:import` + `surface:cli`.
- An agent planning safe diagnostics can match `operation:inspect` + `risk:read_only`.
- An agent working on Bricks package transport can match `scope:addon:bricks` + `object:package`.
- An agent evaluating automated client setup can match `workflow:client_onboarding` and then filter out `risk:destructive`.

### 4.3 Aliases and search terms

Records should include natural-language aliases where users and agents are likely to use different terms, for example:

- `sync`, `migration`, `transfer`, `intake`, and `restore`;
- `command line`, `WP-CLI`, and `headless`;
- `media mapping`, `resolver rules`, and `attachment reconciliation`;
- `Bricks mothership`, `golden source`, `package publishing`, and `connected sites`.

Aliases improve discovery but do not replace structured tags.

## 5. Capability Record Contract

Each manifest record will use a stable ID such as `cli.core.import` or `addon.bricks.drift_scan` and contain, at minimum:

```json
{
  "id": "cli.core.import",
  "title": "Core JSON import command",
  "summary": "Imports staged DBVC JSON artifacts through the core WP-CLI flow.",
  "status": "active",
  "primary_category": "cli_automation",
  "tags": [
    "surface:cli",
    "operation:import",
    "scope:core",
    "risk:wordpress_write",
    "risk:requires_backup"
  ],
  "aliases": ["wp dbvc import", "CLI import"],
  "surfaces": [],
  "requirements": [],
  "inputs": [],
  "outputs": [],
  "storage_touched": [],
  "safety": {},
  "source_refs": [],
  "test_refs": [],
  "doc_refs": [],
  "related": [],
  "known_gaps": [],
  "verification": {}
}
```

The final schema will define these fields:

- `id`
- `title`
- `summary`
- `status`
- `primary_category`
- `tags`
- `aliases`
- `addon_or_owner`
- `runtime_entrypoint`
- `surfaces`
- `requirements`
- `inputs`
- `outputs`
- `artifacts`
- `storage_touched`
- `settings`
- `hooks`
- `safety`
- `source_refs`
- `test_refs`
- `doc_refs`
- `related`
- `known_gaps`
- `verification`

`verification` should include the reviewed commit, date, evidence type, and whether live-runtime confirmation remains outstanding.

## 6. Index And Refresh Model

### 6.1 Canonical versus generated files

- `manifest.json` is curated and reviewable.
- `manifest.schema.json` validates record shape and allowed taxonomy values.
- `generated/discovery-snapshot.json` is derived from source inspection.
- `generated/index-*.md` files are rebuilt entirely from the manifest.
- Generated files must carry a header stating that direct edits will be overwritten.

### 6.2 Planned commands

The exact implementation language will be selected after repository dependency review. The intended developer commands are:

```text
composer agent-docs:discover
composer agent-docs:build
composer agent-docs:check
composer agent-docs:query -- operation:import surface:cli
```

Equivalent repository-local scripts may be used if Composer is not the appropriate integration point.

- `discover` scans approved registration sources and writes the discovery snapshot.
- `build` validates the manifest and regenerates agent indexes.
- `check` performs discovery and validation without accepting changed output; it fails on drift.

### 6.3 Drift checks

The checker should detect:

- a registered CLI command with no manifest surface;
- a registered REST route with no manifest surface;
- an indexed hook, admin-post handler, AJAX action, or settings key with no owning capability or explicit ignore decision;
- a manifest source reference that no longer resolves;
- duplicate IDs, invalid tags, or dangling related-record IDs;
- generated indexes that do not match the manifest;
- a record marked `active` whose runtime entrypoint is no longer loaded;
- source-reference code that becomes runtime-loaded without an explicit status review.

Not every private method or internal option must become its own capability. The checker should support reviewed ignore/grouping rules so the library remains useful rather than exhaustive noise.

### 6.4 Human review boundary

Automation may propose new unclassified surfaces with `unknown_requires_verification`. It must not automatically assign:

- operational safety;
- production readiness;
- destructive impact;
- rollback guarantees;
- recommended agent usage;
- `active` status.

Those fields require code review and, where appropriate, runtime verification.

## 7. Phased Implementation

### Phase 0 — Scope And Authority Lock

Status: Complete through this plan.

#### Tasks

1. Confirm the library is an opt-in layer under `docs/agents/`.
2. Preserve existing docs as authoritative long-form sources.
3. Define current checkout and commit as the initial research baseline.
4. Separate repository evidence from live LocalWP runtime evidence.
5. Establish that no runtime code or live data is changed during documentation discovery.

#### Exit criteria

- This plan is approved.
- Research does not begin until the user authorizes Phase 1/2 work.

### Phase 1 — Minimal Scaffold And Schema

Purpose: Create the reference container before collecting capability claims.

Status: Complete on 2026-08-05.

#### Tasks

1. Create `docs/agents/README.md` with purpose, loading policy, status legend, and index placeholders.
2. Create the initial manifest schema.
3. Create an empty or example-only manifest that cannot be mistaken for complete coverage.
4. Create `taxonomy.md` defining categories, tags, aliases, and naming rules.
5. Add generated-file headers and marker boundaries for the README/index summary.
6. Document existing source docs that will be linked rather than copied.

#### Exit criteria

- Schema validates.
- Empty-state messaging clearly says discovery is incomplete.
- No existing documentation has been moved or rewritten.

### Phase 2 — Read-Only Source Discovery Tooling

Purpose: Build a repeatable inventory mechanism before manually writing records.

Status: Complete on 2026-08-05. The reconciled review is in `docs/agents/DISCOVERY_REVIEW.md`.

#### Initial discovery targets

1. Plugin bootstrap includes and initialized services.
2. WP-CLI namespace and command registrations.
3. Core REST route registrations.
4. Add-on REST route registrations.
5. Admin menu, admin-post, and AJAX registrations.
6. WordPress actions and filters exposed as extension points.
7. Settings and option keys, grouped by owning UI or service.
8. Database tables and durable option/file stores.
9. Scheduled hooks and background jobs.
10. Test files and existing documentation references.

#### Tasks

1. Implement narrow source parsers for the approved registration patterns.
2. Produce `generated/discovery-snapshot.json` with source location and symbol evidence.
3. Add explicit ignore/grouping decisions for private implementation noise.
4. Verify discovery counts manually against direct source searches.
5. Record limitations for dynamic registrations that static discovery cannot resolve.

#### Exit criteria

- Re-running discovery is deterministic.
- Every discovered item has an exact source reference.
- No discovered item is represented as a supported capability without review.

### Phase 3 — Initial Manifest Research And Compilation

Purpose: Convert raw surfaces into accurate capability records.

Status: Complete on 2026-08-05. The initial repository-only manifest contains 43 reviewed records and maps all 261 enforced discovery surfaces.

This is the next major research phase and begins after the discovery boundary is accepted.

#### Research waves

1. **Foundation and bootstrap**
   - loaded services, identity, canonicalization, storage, logging, and extension boundaries.
2. **Import/export and CLI**
   - full/batch/diff/chunk flows, upload routing, CLI options, outputs, failure behavior, and safety.
3. **Proposals, review, masking, and media**
   - proposal lifecycle, snapshots, decisions, duplicates, new entities, resolver, apply, and rollback.
4. **Entity Editor, settings, hooks, and admin operations**
   - supported entity/file operations, configuration surfaces, maintenance actions, and extension points.
5. **Bricks add-on**
   - artifacts, drift, packages, proposals, apply, restore points, connected sites, onboarding, commands, and diagnostics.
6. **Staged, planned, historical, and absent capabilities**
   - Content Migration/Content Collector source reference, Official Collections scaffolding, roadmap items, and checkout-specific absences.

#### Per-record evidence requirements

Each record must be supported by:

- current bootstrap or registration evidence;
- implementation symbol/path evidence;
- applicable tests;
- existing documentation, labeled as current, planning, or historical;
- live-runtime evidence only when source inspection cannot settle current availability.

#### Exit criteria

- Every discovered public surface is mapped, grouped, or explicitly ignored.
- Every record has status, risk, source references, and verification metadata.
- CLI, import, and import-CLI compound queries return the expected records.
- Source-only and absent features cannot appear in active-capability results.

### Phase 4 — Agent Context Facets

Purpose: Turn the manifest into small, task-specific context packets.

Status: Complete on 2026-08-05. Eight facets cover CLI, core import/export, proposals/media, identity/storage, Entity Editor, settings/extensions, Bricks, and non-active boundaries.

#### Tasks

1. Create only the facet files justified by manifest density.
2. Keep each facet focused on orientation, safe usage boundaries, primary records, and authoritative links.
3. Add “load next” guidance so an agent can progressively open only relevant records/docs.
4. Add common gap prompts such as:
   - Does DBVC already expose this operation through CLI or REST?
   - Is there a read-only preview path before apply?
   - Which site role or add-on owns this capability?
   - What must be backed up or verified before invoking it?
5. Avoid copying existing full workflows into the facets.

#### Exit criteria

- A targeted task can be oriented from one index and one facet file.
- Facet files remain materially shorter than the long-form docs they reference.
- No contradictory duplicated status claims remain.

### Phase 5 — Generated Agent Indexes

Purpose: Make compound category/tag discovery fast and deterministic.

Status: Complete on 2026-08-05. Generated rows link to the manifest and recommended facets; alias lookup and compound repository queries are implemented.

#### Tasks

1. Generate indexes by primary category, tag, surface, operation, and risk.
2. Generate a compact summary table into `docs/agents/README.md` between controlled markers.
3. Include record ID, summary, status, risk, and link in every index row.
4. Generate reverse links from tags to relevant facet files.
5. Ensure aliases are searchable without creating duplicate records.
6. Provide copy/paste filter examples for common agent tasks.

#### Exit criteria

- Index output is deterministic and diff-friendly.
- `operation:import` + `surface:cli` resolves directly to import CLI records.
- Generated pages contain no manually maintained capability claims.

### Phase 6 — Verification And Agent-Use QA

Purpose: Confirm the library supports real agent tasks without overstating authority.

Status: Complete on 2026-08-05. Static retrieval scenarios passed, a command-signature discovery gap was corrected, and the authorized newer-checkout runtime comparison is recorded in `docs/agents/PHASE6_VERIFICATION.md` without promoting repository records to live-verified.

#### QA scenarios

1. Find every current WP-CLI command and its arguments.
2. Find only import-related commands and functions.
3. Find read-only diagnostics versus write/apply operations.
4. Determine whether Bricks functionality has CLI parity.
5. Identify which workflows touch WordPress data, the filesystem, or remote sites.
6. Distinguish current runtime add-ons from source-reference or absent add-ons.
7. Find the correct code, tests, and docs for adding a new CLI command.
8. Build a safe new-client-site automation plan without silently invoking destructive operations.

#### Tasks

1. Run static validation and targeted agent retrieval exercises.
2. Compare documented registrations with `wp cli cmd-dump` or equivalent runtime output when a verified LocalWP checkout is available.
3. Compare REST records with the live route index when runtime verification is authorized.
4. Correct status and safety classifications from evidence.
5. Record checkout/runtime provenance and unresolved uncertainty.

#### Exit criteria

- Static and authorized runtime inventories reconcile or document their differences.
- All test queries retrieve concise, correct context.
- No destructive capability is presented without an explicit warning and prerequisite.

### Phase 7 — Maintenance Integration

Purpose: Keep the reference useful as DBVC evolves.

Status: Complete on 2026-08-06. A deterministic source fingerprint replaced volatile `HEAD` metadata, `composer agent-docs:refresh` provides the single rebuild path, relevant pull requests and `master` pushes run a path-scoped strict drift check, and `docs/agents/MAINTENANCE.md` defines capability-impact notes, release checks, and the non-stable-record review cadence.

#### Tasks

1. Add the build/check commands to the repository's existing development workflow.
2. Add CI or pre-release drift validation after the initial manifest stabilizes.
3. Require capability-impact notes when a change adds/removes a public surface.
4. Rebuild indexes as part of relevant documentation updates.
5. Add a lightweight review cadence for records that remain `unknown_requires_verification`, `experimental`, or `planned`.
6. Keep generated output deterministic so maintenance changes remain reviewable.

#### Exit criteria

- New public registrations cannot silently bypass the discovery snapshot.
- Stale or missing manifest mappings are visible before release.
- Maintenance does not require manually editing multiple indexes.

### Phase 8 — Optional Agent Recipes

Purpose: Add bounded operational recipes only after the capability registry is trustworthy.

Candidate recipes include:

- inspect a site before DBVC onboarding;
- generate and verify a safe export;
- stage a proposal without applying it;
- reconcile media before import;
- apply with backup and rollback checkpoints;
- compare Bricks drift without mutation;
- add a new WP-CLI command with REST/service parity;
- evaluate API or CLI parity gaps.

Recipes must reference manifest record IDs and must not become a second source of command signatures or status truth.

## 8. Initial Research Read Order

When Phase 3 is authorized, the first research pass should follow this order:

1. `git status --short` and recent history to lock the checkout boundary.
2. `db-version-control.php` for runtime loading authority.
3. `commands/class-wp-cli-commands.php` for current CLI registrations and contracts.
4. `admin/class-admin-app.php` for core REST and proposal operations.
5. `includes/` bootstrap services, hooks, imports, exports, media, storage, and settings helpers.
6. `admin/admin-page.php` and menu/app hosts for operator surfaces and settings ownership.
7. `addons/bricks/` runtime bootstrap, services, routes, settings, jobs, and tests.
8. `_source/content-collector/` only to classify source-reference boundaries; never as runtime authority.
9. Current tests for supported behavior and regression contracts.
10. Existing docs for terminology, intent, historical context, and known deferrals.
11. Live LocalWP runtime only for unresolved availability questions and only when explicitly in scope.

## 9. Scope Controls

### Included

- Agent-oriented discovery and context routing.
- Current and non-current capability classification.
- CLI, API, admin, PHP, settings, hook, data, storage, add-on, and workflow surfaces.
- Safety and verification metadata.
- Generated indexes and drift checks.

### Excluded unless separately approved

- New DBVC runtime features.
- New CLI commands or REST endpoints.
- Changes to live WordPress data or settings.
- Reorganization or deletion of existing docs.
- In-plugin rendering of the agent library.
- Broad replacement of README or user documentation.
- Treating planned/source-reference code as installed functionality.

## 10. Definition Of Initial Library Complete

The initial library is complete when:

1. The schema, taxonomy, and opt-in entrypoint are stable.
2. Source discovery is repeatable and deterministic.
3. All current public CLI and REST registrations are mapped.
4. Major admin, settings, hook, storage, and add-on capabilities are represented at useful granularity.
5. Every record has evidence, status, safety, and verification metadata.
6. Compound category/tag lookup works for real tasks.
7. Generated indexes rebuild without manual editing.
8. Active, planned, source-reference, deprecated, and checkout-absent records remain clearly separated.
9. Existing long-form documentation remains authoritative and linked rather than duplicated.
10. A drift check identifies newly added or removed public surfaces.

## 11. Approval Gate And Next Action

The approved Phase 7 boundary is now complete:

1. the minimal `docs/agents/` scaffold and schema exist;
2. read-only source discovery and deterministic build/check commands are implemented;
3. the discovery snapshot and research provenance are available in `docs/agents/DISCOVERY_REVIEW.md`;
4. `manifest.json` contains 43 risk-separated capability records;
5. strict coverage maps all 261 enforced surfaces with zero ignored or unmapped items;
6. source-reference, planned, experimental, and checkout-absent work is explicitly separated from active repository capabilities.
7. eight compact facets provide task-specific orientation, safety questions, and progressive “Load Next” routing;
8. generated category, tag, surface, operation, risk, and alias indexes link every result back to the manifest and relevant facets;
9. `composer agent-docs:query -- <terms>` supports compound tags plus status, category, safety, ID, and alias retrieval.
10. a generated WP-CLI command index exposes all repository command signatures and safety owners;
11. all eight agent-use QA scenarios pass, with the lack of command-level automated test references documented;
12. the authorized LocalWP comparison found all 72 baseline REST paths plus 52 newer-checkout-only paths, and 10 baseline CLI capabilities plus three newer media commands;
13. checkout provenance, the live resolver `list_` mismatch, and the prohibition against blending live-only features into this manifest are explicit.
14. `composer agent-docs:refresh` rebuilds the snapshot and all derivative indexes through one command;
15. the snapshot uses a deterministic source fingerprint instead of self-invalidating commit metadata;
16. `.github/workflows/agent-docs.yml` runs strict drift validation for relevant pull requests, `master` pushes, and manual release checks;
17. the pull-request template requires an explicit capability-impact selection and affected-record notes;
18. `docs/agents/MAINTENANCE.md` defines triggers, the canonical change flow, CI failures, release checks, same-checkout verification rules, and a release-or-90-day review cadence.

The initial agent reference library is now implemented through its maintenance boundary. The next optional boundary is Phase 8: add a small number of safety-gated agent recipes that reference manifest record IDs without duplicating command signatures or status authority.

Phase 8 is not required for the inventory, indexes, or drift enforcement to remain useful. No recipe should invoke writes automatically, and no capability should be promoted from repository-active to live-verified until the same checkout is loaded and reconciled.
