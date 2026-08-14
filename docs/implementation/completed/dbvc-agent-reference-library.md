# DBVC Agent Reference Library Implementation Plan

Date: 2026-08-04  
Last updated: 2026-08-14  
Status: Core agent library and administrator landscape complete; capability-shaped verification continues under the approved per-capability boundaries below  
Scope authority: This document defines the implementation sequence for an opt-in agent reference layer under `docs/agents/`. It does not authorize runtime changes, live-site changes, or capability research beyond the explicit phase gates below.

## LocalWP Merge Addendum — 2026-08-06

The completed library and its administrator Capability Landscape were merged into the active LocalWP plugin checkout at HEAD `78a06ad6429574bc1ac88158ec7c037b257dd3a6` without replacing unrelated dirty work.

The current-checkout reconciliation supersedes the original detached-worktree counts in the historical phase notes below:

- 48 curated records;
- 396 strictly owned discovery surfaces;
- 16 WP-CLI leaf commands;
- 126 REST registrations;
- zero unmapped surfaces.

The merge also expanded CLI discovery across all PHP files under `commands/`, reclassified the source-loaded Visual Editor and Content Migration add-ons, and added reviewed records for media hydration, configuration portability, and AI package intake. The canonical implementation path is this file; the opt-in operational entrypoint remains `docs/agents/README.md`.

Bounded same-checkout verification is now recorded in `docs/agents/RUNTIME_VERIFICATION.md`: command help, REST registration, add-on gates, loaded classes, and the administrator renderer were verified without dispatching mutating operations. The manifest baseline remains not fully live-verified, and static or registration evidence does not authorize write operations.

The post-merge release check also confirmed that no ignore/export rule excludes the library and that the CI branch target matches the repository's `master` release naming. Because the added library files remain untracked in the active dirty checkout, intentional commit/package inclusion is still the final release-artifact gate.

## Opportunity Layer Addendum — 2026-08-10

Phase 9 adds an explicit reviewed-opportunity contract without turning REST-without-CLI into an automatic implementation queue. Each reviewed record can now state disposition, priority, effort, recommended interface, rationale, next action, related coverage, and review date.

The initial review contains:

- 3 high-priority CLI candidates: Bricks drift inspection, Entity Editor inspection, and read-only capability list/show/doctor tooling;
- 4 records requiring scope or safety separation before implementation: Bricks control-plane diagnostics, Content Migration readiness, configuration portability, and detailed proposal inspection;
- 3 records marked covered elsewhere because mapped proposal or resolver WP-CLI commands already exist;
- 37 records deliberately left unreviewed.

The generated `index-by-opportunity.md`, compound query filters, and administrator Opportunity filter are derivative views of manifest authority. Same-checkout rendering verified the 3/4/3 reviewed breakdown. Optional Phase 8 operational recipes remain deferred until the read-only capability CLI and its doctor output provide a stable discovery interface.

## Read-only Capability CLI Addendum — 2026-08-10

Phase 10 implements `wp dbvc capabilities list`, `show`, and `doctor` as a read-only namespace backed by the packaged manifest and discovery snapshot.

- `list` supports exact status, category, safety, surface, opportunity, and priority filters plus free-text search and WP-CLI output formats.
- `show` returns one stable manifest record as a compact table or canonical JSON.
- `doctor` reconciles strict surface ownership and reports checkout, source fingerprint, REST registration counts, baseline verification state, and explicit add-on gates without dispatching capability callbacks.

The implementation is isolated in `commands/class-capabilities-cli.php`; the existing command loader receives only the required include. Same-checkout help, list, show, and doctor execution passed. The current authority is 48 records, 396 strictly mapped surfaces, 16 CLI commands, and zero unmapped surfaces.

The completed capability-CLI opportunity is now marked covered elsewhere, leaving 2 candidates, 4 needs-review records, 4 covered records, and 38 unreviewed records. The next boundary should use these read-only commands for bounded inspection/dry-run recipes and continue reviewing unreviewed opportunities before considering any new writer commands.

## Bounded Inspection Recipe Addendum — 2026-08-10

Phase 11 adds one opt-in `docs/agents/RECIPES.md` file rather than a parallel documentation suite. It contains four read-only workflows:

- capability and checkout preflight;
- one-proposal readiness inspection;
- bounded media hydration preflight;
- resolver-rule and snapshot context.

Each recipe references stable manifest IDs, begins from the capability preflight contract, and includes explicit stop rules before any write, export, upload, apply, delete, restore, cleanup, recapture, or remote operation. The strict agent-docs validator now rejects malformed recipe metadata, non-read-only recipe safety, unknown records, duplicate recipe IDs, and duplicate record references.

Repository validation passed with 48 records, 396 mapped surfaces, and zero unmapped. Focused tests cover recipe metadata against manifest authority. Same-checkout execution passed after two evidence-driven bounds were added: proposal readiness now requires a known ID, and media inventory begins at 10 items rather than 100.

The next logical implementation boundary is Phase 12: select one of the two remaining reviewed high-priority read-only candidates—Bricks drift inspection or Entity Editor inspection—and implement it as a separately tested CLI contract. Bricks drift is the smaller first candidate because it already has canonicalization/comparison services and does not require an Entity Editor artifact-download contract.

## Bricks Drift CLI Addendum — 2026-08-10

Phase 12 implements `wp dbvc bricks drift` as a dedicated read-only inspection command over the existing Bricks package, normalization, local-artifact resolution, and drift services.

- Exactly one local source is required: `--package-id` or `--file`; remote discovery and remote comparison are excluded.
- Output defaults to 25 rows, caps row and changed-path expansion, supports exact artifact/status filters, and offers a compact JSON envelope.
- Raw artifact values are excluded. Fingerprint status remains authoritative, while informational path differences are labeled separately because canonicalization can classify an artifact clean while volatile paths differ.
- Write-like flags are rejected, the Bricks add-on must be enabled, and `--fail-on-drift` changes only the process exit status.

Focused tests passed with 6 tests and 40 assertions. Same-checkout execution verified help, a bounded existing-package scan, status and exact-artifact filters, JSON output, and exit `0`/`1` behavior for clean/diverged targets. The refreshed authority contains 49 records, 397 mapped surfaces, 17 CLI commands, and zero unmapped surfaces.

The Bricks drift candidate is now covered by `cli.bricks.drift.inspect`, leaving one reviewed high-priority read-only candidate: Entity Editor inspection. Phase 13 should define that command around metadata/index inspection and a deliberately separate artifact-download decision; it must not inherit Entity Editor write or import authority.

## Entity Editor Inspection CLI Addendum — 2026-08-10

Phase 13 implements `wp dbvc entity-editor list` and `wp dbvc entity-editor inspect <relative-path>` as a cache-only inspection contract over existing Entity Editor index and lock-free file-read services.

- `list` supports exact kind, subtype, provider, match, and duplicate filters; bounded search/pagination; optional cache freshness; and table or JSON output.
- `inspect` requires an exact path already present in the supported cached index and returns indexed metadata, a SHA-256 fingerprint, file size/time, and structural counts without raw field values.
- A missing or over-age cache is a hard stop. The command never calls the index rebuild path because that path writes both transient and disk caches.
- Rebuild, refresh, download, raw/content output, locks, save, merge, import, delete, apply, and force-takeover flags are rejected.

Focused Entity Editor CLI tests passed with 6 tests and 50 assertions. The adjacent capability CLI, landscape, and Entity Editor endpoint suites brought the serial regression boundary to 33 tests and 295 assertions. Same-checkout execution verified help, bounded disk-cache listing, one-file structural inspection, a deliberate stale-cache stop, and identical pre/post cache hashes with the transient absent.

The refreshed authority contains 50 records, 399 mapped surfaces, 19 CLI commands, six bounded read-only recipes, and zero unmapped surfaces. Both reviewed high-priority inspection candidates are now covered, so Phase 14 should refresh the opportunity layer before selecting another implementation. That review should prioritize a narrowly separable read-only diagnostic—such as Bricks control-plane status/schema inspection—while keeping telemetry writes, settings changes, remote operations, and all other mutations outside the boundary.

## Opportunity Boundary Refresh Addendum — 2026-08-11

Phase 14 re-audits the four remaining `needs_review` records against their current callbacks and storage behavior. The opportunity contract now requires every candidate to declare a machine-readable `candidate_scope` and non-empty `excluded_operations`; generated indexes, capability CLI search/rows, and the administrator landscape expose those boundaries.

The resulting queue is:

1. High/small: Bricks doctor over status, UI contract, schema verification, deprecations, and runtime-health readers. Telemetry, stored diagnostic events, settings, package/fleet/remote, apply, restore, and proposal operations are excluded.
2. Medium/medium: Content Migration V2 run list/show/overview. Run creation, visibility, reruns, fixtures, package/execution/recovery, and readiness are excluded; the current readiness GET passes `write_reports=true` and is therefore not read-only.
3. Medium/medium: exact-proposal readiness and bounded entity/duplicate/resolver summaries. Raw values/downloads, the single-entity detail callback that can prune decisions, and every decision/masking/cleanup/recapture/apply operation are excluded.
4. Deferred/low: configuration portability CLI parity, pending reconciliation between its implemented registry/admin handlers and a long-form guide that still labels the workflow unimplemented.

No `needs_review` records remain in the reviewed set. The current breakdown is 3 candidates, 1 deferred, 6 covered elsewhere, and 40 unreviewed records. This phase changes discovery guidance only; it adds no callable command and authorizes no REST or writer execution.

Phase 15 should implement only the first candidate as `wp dbvc bricks doctor`, reusing existing side-effect-free Bricks readers with bounded table/JSON output and explicit rejection of telemetry, settings, remote, package, fleet, apply, restore, and proposal flags.

## Bricks Doctor CLI Addendum — 2026-08-11

Phase 15 implements `wp dbvc bricks doctor` as a dedicated read-only adapter over the existing Bricks status, UI-contract, schema-verification, deprecation, and runtime-health readers.

- Status output is reduced to enabled/role/read-only/fleet/visibility/UI-version fields; site UID, site name, home URL, and site URL are omitted.
- Schema output is bounded to shape, counts, coverage, keys, notes, and warning codes. Raw Bricks option values are never returned.
- Stored UI diagnostic events and package-delivery history are not read. Every unrecognized argument is rejected; the registered synopsis admits only format, table fields, and optional warning-based exit behavior.
- Telemetry, settings, rules, packages, fleet, onboarding, command queues, remote operations, proposals, apply, restore, and rollback remain outside the command contract.

Focused Bricks CLI tests pass with 10 tests and 109 assertions. The capability CLI and landscape suites add 9 tests and 131 assertions. Same-checkout help and JSON execution passed; the active site reported an enabled client role, healthy theme-style/component schemas, zero health/schema warnings, and one deprecation notice. A hash covering Bricks/DBVC Bricks options was identical before and after the command, and `--apply` failed with exit `1`.

The broader Bricks Phase 1 regression exposed a direct-render edge: callers that load `admin-page.php` outside WordPress's `is_admin()` bootstrap did not have the Capability Landscape helper loaded. The capability panel now lazily requires its helper at the render site; the Phase 1 suite passes with 10 tests and 41 assertions, while normal admin bootstrap behavior remains unchanged.

The refreshed authority contains 51 records, 400 mapped surfaces, 20 CLI commands, seven bounded read-only recipes, and zero unmapped surfaces. The Bricks control-plane opportunity is now covered by `cli.bricks.doctor`; two medium-priority candidates remain: bounded Content Migration V2 run inspection and bounded exact-proposal summary inspection. Selection of either is a separate boundary, and Content Migration readiness plus proposal single-entity detail remain excluded because their current read paths can write reports or prune decisions.

## Content Migration Run Inspection CLI Addendum — 2026-08-11

Phase 16 implements `wp dbvc content-migration runs list` and `wp dbvc content-migration runs show <run-id>` as bounded read-only adapters over existing V2 journey artifacts.

- `list` returns each domain's latest exact run with bounded pagination, optional domain/status/search filters, visibility state, URL outcome counts, and issue counts. It reconstructs that run's counts in memory from its existing journey events rather than exposing cumulative domain counts from an older materialized artifact.
- `show` resolves one exact current or historical run and returns sanitized profile shape, inventory statistics, stage summaries, action/issue counts, and bounded recent activity. It omits source URLs, event messages, raw values, artifact paths, and per-URL payloads.
- The reader resolves the existing uploads root without directory creation. It does not call domain-context materialization, readiness generation, REST callbacks, or any create, visibility, rerun, fixture, package, execute, recovery, import, rollback, remote, AI-queue, raw, download, or apply workflow.
- Domain, event, JSON-size, line-size, result, and activity limits bound filesystem work. Unknown flags are rejected; `--readiness` failed with exit `1` in same-checkout verification.

Focused Content Migration and adjacent capability suites pass with 18 tests and 342 assertions. Same-checkout help, bounded list/show execution, and the rejected readiness flag passed against the active LocalWP site. The Butler Automation latest list and exact show both reported 7 discovered and 7 finalized URLs. Pre/post hashing retained the same 12,133 files and tree hash `2c77f4b522095a010dedc594d8369a0bf591d8bfc23130e9e5a73094a571db2e`.

The refreshed authority contains 52 records, 402 mapped surfaces, 22 CLI commands, eight bounded read-only recipes, and zero unmapped surfaces. Content Migration runtime parity is now covered by `cli.content_migration.runs.inspect`. The next implementation boundary is the sole remaining candidate: bounded exact-proposal summary inspection. It must continue to exclude raw current/proposed values, downloads, the single-entity detail callback that can prune decisions, and all decision, masking, cleanup, recapture, apply, and other writer operations.

## Bounded Proposal Structural Inspection CLI Addendum — 2026-08-11

Phase 17 implements `wp dbvc proposals show <proposal-id>` and `wp dbvc proposals entities <proposal-id>` as a deliberately conservative read-only contract over one exact existing manifest, stored review summaries, and existing snapshot artifact metadata.

- `show` returns manifest fingerprints and counts, conservative missing-hash/duplicate blockers, stored decision coverage, and existing snapshot-artifact counts. It explicitly returns `authoritative_apply_ready: null` because field decisions, masking values, live resolver matching, snapshot trust/staleness, new-entity identity, and apply permissions are not evaluated.
- `entities` returns at most 100 sanitized rows with identifiers, types, stored hashes, media-reference counts, decision counts, duplicate-group summaries, and existing snapshot artifact metadata. Raw current/proposed values, titles, paths, URLs, decision payloads, and media references are excluded.
- Exact proposal containment, a 20 MB manifest ceiling, a 20,000-item ceiling, bounded pagination, and rejection of every unknown flag keep the interface narrow. Detail, raw, downloads, resolver matching, decision changes, masking, cleanup, recapture, upload, delete, apply, and all other writers are outside the contract.
- The command intentionally bypasses REST callbacks, backup/snapshot path managers, stable-identity assignment, and media resolver dry-run. Audit showed that these nominal readers can prune stored decisions, create or harden directories, assign stable UIDs, or write attachment UID/hash metadata.

Focused proposal/capability tests pass with 15 tests and 267 assertions, including an out-of-directory snapshot-symlink regression. The adjacent proposal-diff and masking selection passes with 76 tests and 934 assertions. Same-checkout help, bounded show/entities execution, rejected `--raw`, administrator rendering, and identical pre/post artifact/option fingerprints all passed.

The refreshed authority contains 53 records, 404 mapped surfaces, 24 CLI commands, eight bounded read-only recipes, and zero unmapped surfaces. The broader `proposal.core.inspect` record is now classified mixed-risk, while its bounded CLI parity is covered by `cli.proposals.inspect`. No reviewed implementation candidate remains; the next logical proposal boundary is callback-level safety remediation and tests before any broader inspection or authoritative-readiness interface is considered.

## Resolver Dry-run Mutation Barrier Addendum — 2026-08-11

Phase 18 remediates one exact read-side-effect class from the proposal inspection audit: media resolver dry-run and existing media-bundle lookup.

- `Resolver::resolve_descriptor()` now treats `dry_run=true` as a mutation barrier. Hash and relative-path matches still return the same reuse result and target attachment, but do not backfill `vf_asset_uid` or `vf_file_hash`.
- Non-dry-run resolver behavior is unchanged and continues to backfill identity metadata when an operational resolution uses hash or relative path.
- `BundleManager::get_proposal_directory()` and `locate_bundle_file()` now resolve existing storage only. They do not create the sync/media-bundles root or write `.htaccess`/`index.php`; bundle build/ingest paths explicitly retain create-and-harden behavior.
- Focused tests cover dry-run hash matching, dry-run path matching, preserved non-dry-run backfill, and an absent isolated bundle root. The resolver operational and proposal-inspection selection passes with 17 tests and 192 assertions; the final adjacent regression selection passes with 80 tests and 968 assertions.

Same-checkout dry-run over an existing 140-media proposal returned 140 asset-UID reuses and zero conflicts. Pre/post hashing retained the same 522 attachment identity-meta rows and the same 2,359-file media-bundle tree; their hashes were `6b2597630766e792fbd568d8841d265a4219b66caa3fc7abaf34b063a7e91b8e` and `986f7e055f738966c3adc208fa388d5e5f761a97e0d494cb566d8c8ac3d256bc`, respectively.

This phase does not promote the grouped proposal inspection API to read-only. Snapshot/base-path creation, stable-identity assignment during current-state reads, and single-entity decision pruning remain separate mutation classes. The next boundary should select only one of those classes and prove it with callback-level and pre/post storage fingerprints before changing readiness or CLI scope.

## Capability Verification Landscape Addendum — 2026-08-11

Phase 19 makes recorded verification evidence directly reviewable in the existing administrator Capability Landscape rather than creating a separate tracking screen.

- Every record receives one conservative display state: `live_verified`, `scoped_evidence`, `tested`, `source_reviewed`, or `not_recorded`. Full-record `live_runtime_verified=true` takes precedence; same-checkout or pre/post evidence on an otherwise unverified grouped record remains scoped, and repository tests alone remain tested.
- The table adds live/scoped summary counts, a verification filter, a verification column, checked dates, and expandable evidence types, test references, notes, and reviewed commit. Verification notes and evidence are included in free-text search.
- Badges describe evidence only. They do not change safety classification, authorize writes, or promote a partially tested grouped capability to live verified.

Maintenance is per capability, not batch-oriented: after each capability is processed, reviewed, checked, tested, or confirmed, its canonical manifest record must immediately receive the current date, exact evidence types, concise result notes, applicable test references, and revised warnings/gaps. Partial, failed, blocked, and operation-level checks must be recorded rather than omitted. `live_runtime_verified` may become true only for complete same-checkout confirmation of the bounded record. Run `composer agent-docs:refresh` after each update so the administrator table remains the current review ledger.

Same-checkout administrator rendering confirmed the table contract. At the Phase 19 close, the 53-row ledger contained 6 live verified, 4 with scoped runtime evidence, 16 tested, and 27 source reviewed. The verification filter contract, row classifications, details, summary counts, JavaScript predicate, and administrator gate rendered successfully. Interactive browser verification remains open because the available browser session was not authenticated to WordPress; the table record therefore retains scoped evidence instead of a full live-verification claim.

## Trusted Decision-Pruning Boundary Addendum — 2026-08-11

Phase 20 closes the single-entity untrusted-baseline pruning class without broadening proposal CLI or apply authority.

- Entity detail now requires both a trusted snapshot and an available authoritative diff before pruning stale review paths. A trusted status with an unavailable snapshot payload is fail-closed.
- The response reports whether cleanup ran, its source and reason, before/after counts, and the number pruned. Missing or untrusted baselines return `dbvc_decisions_preserved_untrusted_baseline` and leave stored decisions unchanged; new entities report cleanup as not applicable.
- Trusted-snapshot cleanup remains an intentional mutation and the grouped `proposal.core.inspect` record remains mixed-risk. Snapshot/base-path creation and stable-identity assignment during reads are separate unresolved classes.

Focused missing/trusted snapshot tests pass with 2 tests and 21 assertions; the complete proposal-diff contract passes with 55 tests and 642 assertions. Same-checkout execution against missing-snapshot entity `a7b8eb8e-58e5-420c-b86f-283dd53c2433` returned the preservation warning and retained the complete `dbvc_proposal_decisions` option hash `5af0fb763c5d062978866889b3df3bf99b4dbfce80166aa325daecca38620a8b` before and after.

Recording this operation-level evidence moves `proposal.core.inspect` from tested to scoped evidence without promoting the grouped record to live verified. The current table contains 6 live verified, 5 scoped evidence, 15 tested, and 27 source-reviewed records.

## Non-Creating Snapshot Lookup Addendum — 2026-08-11

Phase 21 closes the snapshot lookup path-creation class while retaining capture as a deliberate filesystem writer.

- `DBVC_Snapshot_Manager::get_base_path(false)` uses non-creating upload resolution and returns the configured path without creating or hardening it.
- Snapshot reads, metadata lookup, inspection, and missing-entity deletion resolve paths in non-creating mode. Post and term capture explicitly opt into base creation, then create and harden their proposal directory before writing.
- Focused coverage starts with an absent isolated uploads root, proves reads return null/absent metadata without creating the base or security files, then proves capture still creates the base, `.htaccess`, `index.php`, and a readable snapshot.

The focused snapshot/decision safety selection passes with 3 tests and 30 assertions. Same-checkout lookup under isolated root `/private/tmp/dbvc-snapshot-reader-1c0dfdbc-48b4-4703-93bd-9fef8bbb59b0` left the root, snapshot base, `.htaccess`, and `index.php` absent before and after. `storage.core.snapshots` and `proposal.core.inspect` record this as scoped runtime evidence; `proposal.core.decisions` records the preserved writer contract as repository-tested. The current 53-row ledger contains 6 live verified, 6 scoped evidence, 16 tested, and 25 source reviewed.

This phase does not change backup-base resolution or stable-identity assignment during current-state payload construction. Those remain separate boundaries, and the grouped proposal inspection record remains mixed-risk and globally runtime unverified.

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

## Non-Creating Backup Lookup Addendum — 2026-08-12

Phase 22 separates backup-base lookup from backup creation. `DBVC_Backup_Manager::get_base_path(false)` resolves the existing uploads location without creating it; proposal-list, manifest, payload, preview, and readiness readers now use that path. Backup staging, deletion/download handling, and other writer paths retain the default creating and hardening behavior.

The focused backup plus adjacent snapshot/decision selection passed with 3 tests and 28 assertions. The backup fixture proved `list_backups()` and `read_manifest()` return empty/null for absent storage without creating the root, backup base, `.htaccess`, or `index.php`, then proved the explicit writer base path creates and hardens the same location.

Same-checkout execution used an isolated temporary uploads root. Before and after backup list/manifest reads, its root and backup base were absent and neither security file existed; the list was empty and the manifest result was null. This adds `same_checkout_absent_storage_backup_read` scoped evidence to `proposal.core.inspect` and `storage.core.snapshots`; ledger status totals remain 53 records: 6 `live_verified`, 6 `scoped_evidence`, 16 `tested`, and 25 `source_reviewed`.

This boundary does not make grouped proposal inspection read-only or live-verified. Stable-identity assignment during current-state and snapshot inspection remains the next isolated mutation boundary; no broader readiness parity, capture, cleanup, retention, or apply behavior was changed.

## Stable-Identity Assignment During Current-State and Snapshot Inspection — 2026-08-12

Phase 23 separates identity lookup from identity assignment during snapshot inspection. Post and term inspection payload builders now read existing `vf_object_uid` metadata; they no longer call UID-ensuring helpers that can write post/term metadata and synchronize identity records. Capture continues to use the default writer mode, so it still assigns stable identity when needed before storing a snapshot.

Focused coverage creates post and term snapshots, removes their identity metadata without triggering the unrelated auto-export hook, then inspects each current state. Both inspections report valid existing snapshots while leaving `vf_object_uid` absent; the following explicit capture assigns the UID. The focused snapshot selection passed with 3 tests and 31 assertions.

The `proposal.core.inspect` and `storage.core.snapshots` records now carry `same_checkout_identity_metadata_fingerprint` scoped evidence. Their grouped classification remains mixed because trusted single-entity detail may still prune stale decisions. The next isolated boundary is **Decision-Pruning Write-Path Separation**: assess whether that reported cleanup belongs in an explicit writer rather than a GET callback. No readiness parity, capture, cleanup, retention, or apply behavior was changed in Phase 23.

## Decision-Pruning Write-Path Separation — 2026-08-12

Phase 24 moves trusted stale-decision cleanup out of the single-entity inspection GET callback. Detail now preserves decisions and reports `explicit_action_required` when a trusted snapshot diff makes cleanup eligible. A new administrator-only `POST /dbvc/v1/proposals/{proposal_id}/entities/{vf_object_uid}/selections/prune` route revalidates snapshot trust, calculates current diff and masking paths, removes only stale decisions, and returns exact before/after counts. Missing or untrusted baselines return `dbvc_decision_pruning_unavailable` with HTTP 409 and do not mutate decision storage.

Focused coverage confirms a trusted GET leaves both valid and stale decisions untouched, the explicit writer removes only the stale path, and the untrusted writer attempt leaves the serialized store unchanged. The focused decision-pruning selection passed with 2 tests and 33 assertions.

The complete proposal-diff contract passed with 58 tests and 685 assertions. Same-checkout entity-detail GETs retained the exact serialized decision-store fingerprint `35786c7117b4e38d0f169239752ce71158266ae2f6e4aa230fbbb87bd699c0e3`; the explicit pruning writer was not invoked live because it can remove stale decisions.

This makes `proposal.core.inspect` a read-only capability record; `proposal.core.decisions` owns the explicit writer. The next isolated boundary is **Explicit Decision-Pruning Operator Surface**: determine whether an administrator-facing action is required for the already-authorized writer without reintroducing implicit cleanup or expanding readiness parity.

## Explicit Decision-Pruning Operator Surface — 2026-08-12

Phase 25 adds the required administrator-facing control inside the Proposal Review entity drawer. It appears only when the read-only entity-detail payload reports a trusted diff and at least one stored decision eligible for review. The operator must confirm the exact narrow action before the client posts to the existing pruning route. On success, the drawer, entity/proposal decision summaries, readiness state, and visible toast update from the returned exact counts; on failure, the existing decision error surface receives the server message.

The pruning writer now also fails closed when a snapshot that was trusted during status lookup cannot be re-read into an authoritative diff at execution time. Its response includes entity and proposal summaries so the UI does not rely on an implicit follow-up write or a stale count.

Focused server/UI-source coverage passed with 3 tests and 84 assertions; the full proposal-diff contract passed with 58 tests and 702 assertions; and the scoped `admin-app` production build completed successfully. This boundary does not invoke the writer against live proposal data, because it can delete stale decisions; it does not add apply/readiness parity or alter the read-only inspection route.

The next isolated boundary is **Proposal Decision Operator Runtime QA**: obtain an authenticated administrator session with a trusted-snapshot fixture to exercise the visible confirmation, successful count refresh, no-op result, and 409 failure copy without using client data. No broader decision writer changes are included.

## Proposal Decision Operator REST Authorization Contract — 2026-08-12

Phase 26 completes the server-side portion of the operator QA boundary through the registered REST route, not a direct callback. A subscriber receives `403 rest_forbidden` and leaves both valid and stale decision entries unchanged. The same trusted-snapshot fixture then runs as an administrator, receives `200`, removes only the stale entry, and returns the exact entity and proposal summaries that the drawer consumes. Source-contract coverage also verifies the drawer hides the action with no stored choices and handles the returned summaries and failure copy.

The focused authorization/untrusted/UI-source selection passed with 3 tests and 84 assertions. This is fixture-based WordPress REST evidence, not an authenticated browser run. The remaining browser-only portion is **Proposal Decision Operator Browser QA**: exercise visible confirmation, successful/no-op count refresh, and 409 copy in an authenticated administrator session without touching client data.

The same-checkout browser was rechecked and redirects to the administrator login page. That browser QA boundary remains gated on user authentication; no credentials or client-data mutation were attempted.

## Proposal Decision Operator Authenticated Fixture QA — 2026-08-12

An authenticated administrator session displayed the gated `Prune stale decisions` action and exact confirmation wording for a disposable local fixture. The fixture writer removed two stale choices, retained one valid choice in a no-op response, and rejected a removed-snapshot request with `409 dbvc_decision_pruning_unavailable` while preserving the two stored choices. No client proposal or decision data was used.

The in-app browser-control bridge stalled when the native confirmation appeared, so a complete visible success-toast/409-error-refresh assertion is deferred to a conventional interactive browser. A later drawer reload retained stale fixture decision state despite the writer response, so that presentation state is not promoted to live verification. The temporary pages, proposal and snapshot folders, and fixture option state were all removed. No broader writer, readiness, or apply boundary is approved by this QA result.

## Proposal Decision Operator Browser UI Refresh QA — 2026-08-12

Phase 27 adds `cache: "no-store"` to the shared Proposal Review GET helper, so entity and proposal refreshes do not reuse stale browser responses. The authenticated browser had continued to show the previously removed disposable fixture and its prune action although the active database, fixture pages, option state, proposal folder, and snapshot folder were absent. Reloading the same page after the fix showed zero matching fixture rows and zero prune actions.

The capability ledger records this as same-checkout browser refresh evidence, not full runtime promotion. **Next isolated boundary — Proposal Decision Operator Native Confirmation UI QA:** verify the visible success-toast and 409 error surface through a conventional interactive browser that can handle the native confirmation dialog; do not expand writer, readiness, or apply scope.

## Proposal Decision Operator In-App Confirmation UI QA — 2026-08-12

Phase 28 replaces the native stale-decision prompt with an in-app modal, keeping the same confirmation wording and explicit POST boundary. Authenticated fixture QA confirmed the modal appears, Cancel leaves the action available, and Confirm reaches the no-op and stale-prune writer paths. The implementation adds a persistent, accessible drawer success-status surface based on the returned counts; targeted browser verification of that new surface belongs to the next boundary.

**Next isolated boundary — Proposal Decision Operator Fail-Closed Error UI QA:** hold a stale eligible drawer open, invalidate only its disposable snapshot, confirm the modal, and verify the returned 409 text is shown while decisions remain intact. No client data, broader writers, readiness, or apply behavior is in scope.

## Proposal Decision Operator Fail-Closed Error UI QA — 2026-08-12

Phase 29 completed the modal's fail-closed browser slice with a new, isolated fixture. The eligible confirmation stayed open while only that fixture's trusted snapshot was removed; `Confirm prune` then visibly returned `Stale decisions can be pruned only after a trusted current-state snapshot is available.` The drawer continued to show `1 accepted · 1 kept · 0 declined`, and the fixture inspection confirmed the two stored choices remained intact. Cleanup verified no namespaced posts, decisions, proposal/snapshot directories, or backup directories remained.

This is targeted authenticated-browser evidence for the returned 409 surface and preservation semantics, not promotion of the capability to globally live-runtime verified. **Next isolated boundary — Proposal Decision Operator Persistent Success Status UI QA:** use a fresh disposable stale fixture to verify the accessible in-drawer success status displays the authoritative removed/current counts after a successful prune. No client data, broader writers, readiness, or apply behavior is in scope.

## Proposal Decision Operator Persistent Success Status UI QA — 2026-08-12

Phase 30 verified the accessible successful-prune result through the authenticated drawer. A fresh disposable stale fixture was confirmed through the modal; once its authoritative refresh completed, the same drawer displayed `Stale decisions pruned` and `2 stale choices were removed; 0 current choices remain.` The selection summary changed to no selections and the prune action disappeared. Fixture inspection confirmed those two target choices were gone, and cleanup confirmed that no fixture artifacts remained.

This completes the visible returned-error and returned-success result slices but does not promote the grouped record to live-runtime verified. Phase 31 completes the keyboard check below and identifies the focused restoration defect for a separate implementation boundary.

## Proposal Decision Operator Modal Keyboard Accessibility QA — 2026-08-12

Phase 31 verified safe cancellation but found a focus-management defect. Using a fresh disposable stale fixture, Escape closed the confirmation without sending the writer, kept the prune action available, and left both fixture decisions unchanged. Browser focus inspection showed focus remained on the active entity-list control when the modal opened, then returned to the drawer `Close` control after Escape rather than to `Prune stale decisions`.

The fixture and all artifacts were removed. This is a recorded failed accessibility check, not a code change or runtime-verification promotion; Phase 32 resolves it below without changing prune-writer, readiness, or apply behavior.

## Proposal Decision Operator Modal Focus Restoration Fix — 2026-08-13

Phase 32 implements the narrow focus correction: the drawer no longer restores its prior focus on modal state changes, the prune trigger is retained, the rendered modal focuses `Cancel`, and cancellation paths restore that trigger. The focused source-contract suite passed with 58 tests and 715 assertions; the rebuilt `admin-app` completed successfully. Browser QA then verified initial `Cancel` focus plus restoration to `Prune stale decisions` after Escape, Cancel, and the WordPress modal Close control. The writer was deliberately not sent, both decisions remained intact, and fixture cleanup was complete.

This resolves the Phase 31 defect but does not promote the capability to globally live-runtime verified. The remaining operator UI closeout is tracked as the compact matrix below; no prune writer, readiness, or apply behavior is in scope.

## Remaining Proposal Decision Operator QA Matrix — 2026-08-13

| Case | Current state | Rerun rule |
|---|---|---|
| Successful stale prune and persistent counts | Verified | Only after relevant pruning/result UI changes |
| No-op prune | Verified | Only after relevant pruning/result UI changes |
| Fail-closed 409 and preserved decisions | Verified | Only after snapshot/pruning error-path changes |
| Modal Escape, Cancel, Close, and opener restoration | Verified | Only after modal/focus changes |
| Drawer close restores the originating entity control | Remaining | Run once as non-writer closeout QA |

For the remaining case, perform one checkout preflight, create one namespaced disposable fixture, open and close one entity drawer, verify focus restoration and unchanged decisions, then clean the fixture. Record one compact result in `RUNTIME_VERIFICATION.md` and update the owning manifest record once. Source tests/builds are unnecessary unless the QA exposes a defect that requires code changes; `agent-docs:refresh`, `agent-docs:check`, and `git diff --check` are required only when the manifest is updated.

This operator matrix is a capability-specific example, not a universal test template. Future capabilities select only their applicable source, CLI/API, writer, UI, add-on, or generated-document evidence under the capability-shaped policy in `docs/agents/MAINTENANCE.md`; they do not inherit this fixture, browser, or case list unless their own claim requires it.

Do not create a new run-ledger format for this single case. A machine-readable evidence ledger is a future option only if recurring matrices across multiple capabilities make the manifest and compact runtime summaries insufficient.
