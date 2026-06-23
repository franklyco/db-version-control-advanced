# DBVC Proposal and Diff Minor Update Implementation Guide

Date: 2026-05-23

Related docs:

- `docs/PROPOSAL_DIFF_SYSTEM_AUDIT_2026_05.md`
- `docs/PROPOSAL_DIFF_V2_DEPENDENCY_MANIFEST_2026_05.md`
- `docs/PROPOSAL_DIFF_V2_GATED_ROLLOUT_PLAN.md`

Status: proposal only. No code changes have been made for this guide.

## Goal

Ship a minor update that makes the core DBVC Proposal/diff system safer, easier to audit, and easier for humans and agents to operate without rewriting the whole admin app.

The update should preserve existing REST route shapes where practical and focus on:

- correcting known broken paths
- exposing a reliable preflight plan before apply
- making skip and stale-state reasons machine-readable
- preserving apply evidence
- clarifying documentation and workflow semantics
- creating narrow service boundaries that make future work safer

## Second-Pass Planning Reconciliation

This guide has been reconciled against:

- `docs/DBVC_ENGINE_INVENTORY.md`
- `docs/ROADMAP.md`
- `docs/admin-app-refactor-plan.md`
- `docs/media-sync-design.md`
- `docs/meta-masking.md`
- `docs/legacy-upload-immediate-import-plan.md`
- `docs/CROSS_SITE_ENTITY_PACKET_IMPLEMENTATION_GUIDE.md`
- `docs/DBVC_CONFIGURATION_PORTABILITY_TOOL_IMPLEMENTATION_GUIDE.md`

The strongest reusable model is configuration portability: safe extraction, staged review, compatibility warnings, apply preflight, backup-before-write, provider-level apply, rollback, and explicit partial failure reporting. The core Proposal/diff update should adopt those principles in the proposal domain without forcing a full rewrite.

The strongest compatibility constraint is transfer packets. Cross-site entity packets intentionally reuse proposal upload, review, resolver, and apply. Generic proposal preflight must preserve existing transfer packet fields and warnings.

The strongest simplification decision is this:

> Preflight becomes the contract boundary. The UI, CLI, agents, and apply route should all ask the same service what will happen before anything writes.

## Non-Goals

- Do not rewrite the whole admin app in this release.
- Do not move proposal decisions to a new database table unless option-backed storage becomes a measured blocker.
- Do not expand into Visual Editor, Bricks portability, AI packages, or Content Collector work.
- Do not make official collections part of the first stabilization pass unless explicitly scoped.
- Do not change proposal bundle format in a breaking way.
- Do not merge legacy upload, targeted immediate import, transfer packet intake, and proposal review into one universal intake in this release.
- Do not make every entity type path-diffable in this release.
- Do not require a new frontend build architecture to land backend safety fixes.

## Simplified Target Model

The minor update should simplify the system around five contracts.

### 1. Proposal Bundle Contract

A proposal bundle is immutable review input after upload.

- `manifest.json` is authoritative for proposal contents.
- Entity payloads are read-only review inputs.
- Maintenance operations, such as duplicate cleanup, must be explicit and logged.
- Upload validation must happen before registration.

### 2. Snapshot Contract

Path-level diff requires a trusted current-state source.

- If a live snapshot exists and matches the entity identity, path-level review is allowed.
- If no snapshot exists, show `snapshot_missing`.
- If a snapshot is stale or cannot be trusted, show `snapshot_stale` or `snapshot_untrusted`.
- Do not prune stored decisions from an empty diff unless the diff source is trusted.

### 3. Decision Contract

Accepted decisions are the only default write contract.

- Existing posts/CPTs can use path-level decisions.
- New posts/CPTs require explicit `accept_new`.
- Existing terms can use path-level decisions only after term masking/apply coverage is fixed.
- New terms require explicit `accept_new`.
- Options, option groups, menus, and broad third-party entities require whole-entity acceptance or explicit preflight warning until path-level apply is proven.
- Unsupported or ambiguous entities remain inspect-only.

### 4. Preflight Contract

Preflight answers the operational question before apply:

- what will be created
- what will be updated
- what will be skipped
- why each skip happens
- what media will do
- what masking will do
- what warnings are blocking versus informational
- what receipt or snapshot evidence will be written

Preflight should be deterministic, machine-readable, and safe to run repeatedly.

### 5. Receipt Contract

Apply must leave evidence.

- Record user, timestamp, proposal ID, mode, manifest checksum, decision revision, resolver revision, preflight summary, write results, media results, skipped reasons, and errors.
- Write the receipt before clearing active decisions.
- Keep active decision auto-clear behavior only after the receipt exists.

## Entity Apply Contract Matrix

| Entity type | Current behavior | Minor-update contract | Notes |
| --- | --- | --- | --- |
| Existing post/CPT | Path-level decisions apply through `import_post_from_json()`. | Keep path-level decisions. Require trusted diff source for bulk stamping and pruning. | This is the best-supported path. |
| New post/CPT | Requires `accept_new`; creation then treats required fields as accepted. | Keep explicit `accept_new`; include whitelist/status/create setting checks in preflight. | Receipt should record created ID and UID mapping. |
| Existing term | Path-level decisions exist, but masking path has a defect. | Support after term masking fix and tests. Until then, preflight should flag term masking risk. | Parent updates and term meta need explicit skip reasons. |
| New term | Requires `accept_new` and term creation setting. | Keep explicit `accept_new`; preflight must show taxonomy existence and term creation setting. | Transfer packets depend on this path. |
| Options | Full apply can update option payloads. | Require whole-entity acceptance or block with warning until granular option decisions exist. | Aligns with roadmap item for granular options controls. |
| Option groups | Imported through option-group helper. | Require whole-entity acceptance or block with warning. | Do not silently apply through full mode. |
| Menus | Imported through existing menu importer. | Require whole-entity acceptance or block with warning. | Menu writes are high-impact and hard to partially roll back. |
| Third-party | Imported if third-party portability class exists. | Require provider-declared preflight capability and whole-entity acceptance. | Avoid generic assumptions. |
| Media | Resolver/reconciler plus legacy sync may all participate. | One authoritative media plan in preflight; receipt records final actions. | Preserve resolver decisions and bundle metadata. |

## Compatibility Rules

1. Keep existing proposal upload and apply REST routes available.
2. Keep current proposal directories and `manifest.json` bundles readable.
3. Keep `dbvc_proposal_decisions` and `dbvc_resolver_decisions` readable.
4. Add new response fields rather than replacing current fields when possible.
5. If decisions are auto-cleared after apply, write an apply receipt first.
6. Prefer additive CLI commands and JSON output over changing default human output.
7. Preserve transfer packet `origin`, `selection`, `requirements`, `warnings`, and existing transfer `preflight` fields.
8. Preserve legacy upload and targeted immediate-import behavior.
9. Preserve current UI apply behavior while adding server-side preflight and receipts; require stricter server-side confirmation only after a compatibility period.

## Do-Not-Break Baseline

Before changing behavior, capture tests or smoke checks for:

- proposal upload with a normal `manifest.json`
- transfer packet upload and destination warnings
- proposal list resolver metrics
- entity list diff counts
- entity drawer diff and decision controls
- bulk accept, unaccept, and keep removal
- masking apply and revert
- resolver decision save, reset, global rule import/export
- partial apply for existing posts
- full apply for existing posts
- new post acceptance and creation
- term review and term apply
- duplicate cleanup modal path
- CLI proposal list, upload, apply, recapture, and duplicate cleanup

If a baseline cannot be tested yet, document that explicitly before implementation starts.

## Phase 0: Safety Fixes

Phase 0 should be small, heavily tested, and shippable by itself.

### 0.1 Fix CLI Duplicate Cleanup Confirmation

Problem:

- The CLI cleanup flow references `DBVC_Admin_App::DUPLICATE_BULK_CONFIRM_PHRASE`, but that constant is private.

Implementation:

- Add a public method or public constant for the duplicate cleanup confirmation phrase.
- Alternatively, keep the admin-app constant private and define a CLI-local literal with a shared test expectation.
- Prefer a single public helper if both REST and CLI must stay in sync.

Files likely involved:

- `admin/class-admin-app.php`
- `commands/class-wp-cli-commands.php`
- a targeted CLI or unit test file

Acceptance:

- The duplicate cleanup CLI path can run its confirmation check without fatal errors.
- The confirmation phrase remains unchanged for current users.

### 0.2 Fix Term Masking During Apply

Problem:

- The term apply path references proposal masking overrides without receiving them in scope.

Implementation:

- Pass proposal mask directives into `DBVC_Sync_Posts::apply_term_entity()`.
- Ensure term field and term meta masking use the same masking decision source as post import.
- Add tests that prove a masked term field or term meta value is not overwritten unexpectedly.

Files likely involved:

- `includes/class-sync-posts.php`
- `tests/MaskingEndpointsTest.php` or a new proposal apply masking test

Acceptance:

- No undefined variable warning in the term apply path.
- Term masking decisions affect term imports predictably.
- Existing post masking behavior remains unchanged.

### 0.3 Fix Bulk Diff Path Generation

Problem:

- `resolve_entity_diff_paths()` has a fallback that compares the proposed payload to itself, which always produces no diff paths.

Implementation:

- Replace the no-op fallback with explicit behavior:
  - If a live snapshot exists, compare snapshot current to proposed.
  - If no snapshot exists and the entity is new, return valid create/whole-entity decision semantics.
  - If no snapshot exists and the entity exists locally, return a blocking "snapshot_missing" state instead of silently producing no paths.
- Return machine-readable skip reasons for bulk accept/unaccept when paths cannot be resolved.

Files likely involved:

- `admin/class-admin-app.php`
- new or existing proposal decision endpoint tests

Acceptance:

- Bulk accept/unaccept stamps decisions when a real diff exists.
- Bulk accept/unaccept does not silently succeed with zero paths unless the entity truly has no actionable diff.
- Missing snapshot cases return explicit status.

### 0.4 Preflight Zip Entries Before Extraction

Problem:

- Upload extraction currently relies on later manifest validation after the zip is extracted.

Implementation:

- Before `ZipArchive::extractTo()`, inspect every entry name.
- Reject:
  - absolute paths
  - drive-letter paths
  - `..` path segments
  - empty names
  - unsafe control characters
  - entries that normalize outside the extraction root
- Keep existing manifest payload and media bundle validation after extraction.

Files likely involved:

- `admin/class-admin-app.php` initially
- later extraction into `includes/Dbvc/Proposal/UploadValidator.php`

Acceptance:

- Malicious zip path tests fail before extraction.
- Valid proposal zip uploads still work.

### 0.5 Centralize Proposal ID Normalization

Problem:

- Proposal IDs are route-bound and sanitized in multiple ways.

Implementation:

- Add a single proposal ID normalizer.
- Allow only known-safe proposal directory names.
- Use it in:
  - REST route handlers
  - proposal file reads
  - delete/status/apply paths
  - CLI proposal commands

Files likely involved:

- `admin/class-admin-app.php`
- `commands/class-wp-cli-commands.php`
- future `ProposalRepository`

Acceptance:

- Invalid proposal IDs are rejected consistently.
- Existing normal proposal IDs remain valid.

### 0.6 Stop Unsafe Decision Pruning

Problem:

- Entity detail currently prunes stored decisions after building a diff.
- When current state falls back to proposed payload because the snapshot is missing, the diff can be empty and existing decisions can be removed by viewing the entity.

Implementation:

- Make diff source explicit: `snapshot`, `live_current`, `proposed_fallback`, or `missing`.
- Only prune decisions when the diff source is trusted and authoritative.
- If the diff source is `proposed_fallback` or `missing`, return a warning and leave stored decisions untouched.
- Add tests proving decisions survive entity drawer load when no snapshot exists.

Files likely involved:

- `admin/class-admin-app.php`
- future `includes/Dbvc/Proposal/DiffService.php`
- proposal decision endpoint tests

Acceptance:

- Viewing an entity cannot erase reviewer decisions when current-state evidence is missing.
- Decision pruning still removes stale paths when a trusted diff proves they are stale.

### 0.7 Unify Resolver Context

Problem:

- Proposal list, entity list, detail, and apply do not all call the media resolver with the same proposal context.

Implementation:

- Add one resolver summary helper used by:
  - proposal list
  - entity list
  - entity detail where needed
  - proposal preflight
  - apply receipt
- Always pass proposal ID, manifest directory, bundle metadata, and dry-run/remote policy explicitly.
- Include resolver freshness and source context in preflight output.

Files likely involved:

- `admin/class-admin-app.php`
- `includes/class-sync-posts.php`
- future `includes/Dbvc/Proposal/PreflightService.php`

Acceptance:

- Resolver status does not change merely because the user moved from proposal list to entity list.
- Preflight and apply receipt can explain media actions from one authoritative resolver report.

## Phase 1: Proposal Service Boundaries

This phase should reduce risk without changing behavior.

### 1.1 Add Proposal Repository

Purpose:

- Centralize proposal directory lookup, manifest reads, entity payload reads, snapshot path lookup, and proposal ID validation.

Suggested class:

- `includes/Dbvc/Proposal/Repository.php`

Responsibilities:

- normalize proposal IDs
- resolve proposal root directories
- read and validate `manifest.json`
- locate entity payloads safely
- locate snapshot payloads
- expose manifest checksum and mtimes
- provide safe proposal deletion and maintenance path helpers

Keep out:

- diff rules
- decisions
- apply mutation logic
- UI response shaping

### 1.2 Add Diff Service

Purpose:

- Move flattening, ignore path matching, section classification, and diff summaries out of the admin controller.

Suggested class:

- `includes/Dbvc/Proposal/DiffService.php`

Responsibilities:

- compare current and proposed payloads
- classify diff paths
- return missing snapshot and stale snapshot states
- build path summaries for REST and CLI
- expose a reusable `resolveActionablePaths()` method for bulk decisions
- decide whether a diff source is trusted enough for decision pruning

Acceptance:

- Existing entity detail diff responses remain compatible.
- Bulk decisions use the same path resolver as entity detail.

### 1.3 Add Decision Store

Purpose:

- Encapsulate option-backed decision reads/writes and prepare for future storage without route churn.

Suggested class:

- `includes/Dbvc/Proposal/DecisionStore.php`

Responsibilities:

- get decisions for proposal/entity
- set path decision
- set bulk decisions
- clear decisions
- update status summary
- export/import decisions
- track a simple decision revision or updated timestamp
- preserve decisions when a requested mutation is skipped

Acceptance:

- Existing option keys remain readable.
- REST response fields remain compatible.
- Skipped/stale decision writes can return structured reasons.

### 1.4 Add Upload Validator

Purpose:

- Pull proposal ZIP and manifest validation out of the admin controller and match the safer configuration portability extraction pattern.

Suggested class:

- `includes/Dbvc/Proposal/UploadValidator.php`

Responsibilities:

- preflight zip entries before extraction
- locate `manifest.json`
- validate manifest shape
- validate payload paths
- validate media bundle asset paths
- validate duplicate entities
- return structured issues and warnings

Acceptance:

- `import_proposal_from_zip()` delegates validation without changing route behavior.
- Unsafe zip entries are rejected before extraction.
- Transfer packet additive metadata remains accepted.

## Phase 2: Preflight and Apply Receipts

### 2.1 Add Proposal Preflight Endpoint

Suggested endpoint:

- `GET /dbvc/v1/proposals/{proposal_id}/preflight`

Optional later endpoint:

- `POST /dbvc/v1/proposals/{proposal_id}/preflight` for mode-specific previews.

Response should include:

- proposal ID, title, created time, source URL, manifest schema version
- entity count by type and status
- accepted, rejected, kept, skipped, and pending decision counts
- new entity decisions
- missing or stale snapshots
- non-post entity apply eligibility
- media resolver summary
- masked paths summary
- duplicate status
- blocking errors
- warnings
- exact apply plan for partial and full modes
- transfer packet `origin`, `selection`, `requirements`, existing transfer warnings, and transfer-specific preflight
- preflight revision or token if the release chooses to add server-side apply confirmation

Use cases:

- UI apply confirmation panel
- agent review before apply
- CLI `wp dbvc proposal preflight --format=json`
- automated smoke tests

Rules:

- Preflight must be read-only.
- Preflight must not capture snapshots by itself.
- Preflight must not prune decisions.
- Preflight must not write resolver decisions.
- Preflight must not mutate proposal status.
- Preflight may report that a snapshot recapture is required.

### 2.2 Add Apply Dry Run

Suggested behavior:

- Add `dry_run: true` support to the existing apply pathway only if it can be done without risking writes.
- If a safe dry run inside import logic is too invasive, build dry-run output from preflight first and label it as plan-only.

Response should include:

- entities that would be created
- entities that would be updated
- entities that would be skipped
- paths that would be written
- media actions
- resolver decisions used
- masking actions
- reasons for every skip

### 2.3 Add Apply Receipt

Purpose:

- Preserve what was actually applied before active decisions are cleared.

Suggested class:

- `includes/Dbvc/Proposal/ApplyReceiptStore.php`

Suggested storage:

- per-proposal JSON file under the proposal directory or a DBVC logs directory
- include timestamp, user ID, proposal hash, selected mode, decisions used, resolver decisions used, media report, import result, errors, warnings, and skipped reasons

Acceptance:

- A successful apply leaves a durable receipt.
- Auto-clear can remain enabled without losing evidence.
- UI and CLI can show last apply receipt.

### 2.4 Add Server-Side Apply Confirmation Without Breaking Existing Clients

Purpose:

- Align proposal apply with the stronger confirmation model used by configuration portability without breaking existing UI and CLI flows immediately.

Implementation:

- First release:
  - accept optional `confirm_apply` and `preflight_token`
  - include warnings when they are missing
  - log whether apply was preflight-confirmed
- Later release, if desired:
  - require `confirm_apply` for full apply
  - require a fresh preflight token for high-impact non-post entities

Acceptance:

- Existing clients still work in the first stabilization release.
- New UI and CLI paths can opt into stricter confirmation immediately.

## Phase 3: Human and Agent Workflow Improvements

### 3.1 UI Improvements

Add lightweight UI states before any major frontend refactor:

- "Snapshot missing" badge on entity rows and drawer.
- "Snapshot stale" badge if the snapshot is older than local modified state when detectable.
- "No accepted paths" warning before apply.
- "Partial apply excludes this entity type" warning.
- Resolver summary details in apply confirmation.
- Clear stale-decision response when a path no longer exists.

Keep these changes narrow and compatible with the current app source.

### 3.2 CLI Improvements

Suggested commands or flags:

- `wp dbvc proposals list --format=json`
- `wp dbvc proposal inspect <id> --format=json`
- `wp dbvc proposal preflight <id> --mode=partial|full --format=json`
- `wp dbvc proposal decisions export <id>`
- `wp dbvc proposal decisions import <id> <file>`
- `wp dbvc proposal receipt <id> --format=json`
- `wp dbvc proposal validate <id> --format=json`

Agent-specific requirements:

- deterministic JSON
- stable keys
- explicit warnings and blocking errors
- no human-only prose in JSON output
- nonzero exit for blocking preflight failures when requested

### 3.3 Documentation Corrections

Update docs after code behavior is agreed:

- Standardize bundle manifest name as `manifest.json`.
- Document current upload/proposal storage paths.
- Document media bundle storage and resolver behavior.
- Clarify "backup", "proposal", "proposal bundle", and "official collection".
- Clarify "core content proposal" versus Bricks add-on proposal terminology.
- Add partial/full apply behavior table by entity type.
- Add masking behavior examples for posts and terms.
- Add agent workflow examples using CLI JSON output.
- Update media sync docs to stop referring to `dbvc-manifest.json` for active proposal bundles unless describing legacy behavior.

## Phase 4: Performance and Scale

Only start this phase if large proposal review is measurably slow.

### 4.1 Proposal Index Cache

Cache per-proposal summary data keyed by:

- manifest checksum
- manifest mtime
- snapshot directory mtime or snapshot index checksum
- decision revision
- resolver rule revision
- media index checksum

Cached data:

- entity counts
- diff counts
- snapshot states
- resolver states
- duplicate states
- decision summaries

### 4.2 Paginated and Lazy Diff Loading

Keep list views light:

- load entity summary rows first
- load heavy path diff detail on drawer open
- reuse in-flight requests
- avoid recomputing resolver summaries for every list render

### 4.3 Media Resolver Cache

Cache resolver results by:

- proposal ID
- media index checksum
- bundle metadata checksum
- global resolver decision revision

Expose whether resolver results are fresh or stale.

## Suggested File Plan

Backend additions:

- `includes/Dbvc/Proposal/Repository.php`
- `includes/Dbvc/Proposal/DiffService.php`
- `includes/Dbvc/Proposal/DecisionStore.php`
- `includes/Dbvc/Proposal/PreflightService.php`
- `includes/Dbvc/Proposal/UploadValidator.php`
- `includes/Dbvc/Proposal/ApplyReceiptStore.php`
- `includes/Dbvc/Proposal/ResolverSummaryService.php`

Backend modifications:

- `admin/class-admin-app.php`
  - keep routes
  - delegate repository, diff, decision, upload validation, and preflight work
  - keep response compatibility
- `includes/class-sync-posts.php`
  - fix term masking
  - expose import plan data or apply receipt hooks
  - clarify non-post partial behavior
- `includes/class-backup-manager.php`
  - use strict proposal ID normalization for public backup/proposal operations where the input is a proposal folder
- `commands/class-wp-cli-commands.php`
  - fix cleanup confirmation
  - add JSON preflight/inspect/receipt commands

Frontend modifications:

- `src/admin-app/index.js`
  - consume new preflight response
  - show snapshot and partial apply warnings
  - show stale decision skip responses
  - keep refactor limited unless a separate frontend cleanup is scoped

Documentation updates:

- `README.md`
- `docs/DBVC_ENGINE_INVENTORY.md`
- `docs/ROADMAP.md`
- proposal-specific docs added by this update

## Test Plan

### Unit and Integration Tests

Add or extend tests for:

- duplicate cleanup CLI confirmation
- zip path traversal rejection
- proposal ID normalization
- diff path generation with live snapshot
- diff path generation with missing snapshot
- entity drawer load does not prune decisions when the snapshot is missing
- entity drawer load prunes stale decisions only with a trusted diff source
- new entity whole-entity decision behavior
- bulk accept/unaccept with actionable paths
- stale bulk decision skip reason
- resolver status consistency across proposal list, entity list, and preflight
- term masking on field and meta updates
- partial apply for post entities
- partial apply behavior for term and non-post entities
- full apply gating for options and menus
- apply receipt persistence
- resolver decision identity based on stable media fields
- transfer packet preflight compatibility
- legacy upload immediate-import regression guard if shared helpers are touched

### Existing Tests To Run

Use the narrowest useful set first:

```bash
vendor/bin/phpunit --filter TransferPacketWorkflowTest
vendor/bin/phpunit --filter MaskingEndpointsTest
vendor/bin/phpunit --filter EntityEditorEndpointsTest
```

Then run any new proposal-specific tests added in the update.

Also run transfer/configuration tests when shared upload/preflight helpers are touched:

```bash
vendor/bin/phpunit --filter TransferPacketWorkflowTest
vendor/bin/phpunit --filter ConfigurationPortabilityExportPackageTest
```

### Syntax and Diff Checks

Run:

```bash
php -l admin/class-admin-app.php
php -l includes/class-sync-posts.php
php -l commands/class-wp-cli-commands.php
git diff --check
```

Adjust the `php -l` list to match touched PHP files.

### Browser Smoke Checks

If the admin UI is changed, run a focused LocalWP smoke check on `dbvc-codexchanges.local`:

- proposal list loads
- proposal entity list loads
- entity drawer loads
- snapshot missing/stale labels render correctly
- decision write succeeds
- stale decision response is visible
- preflight panel opens
- apply confirmation shows blocking warnings

Do not claim save/apply validation unless a disposable fixture proposal was actually applied.

## Rollout Order

Recommended release order:

1. Phase 0 safety fixes and tests.
2. Preflight service with no UI apply behavior change.
3. Apply receipt persistence.
4. UI warning and confirmation improvements.
5. CLI JSON workflows.
6. Documentation corrections.
7. Optional performance cache.

This order gives immediate correctness wins while preserving the existing user workflow.

## Acceptance Criteria For The Minor Release

The release is ready when:

- the known CLI, term masking, and bulk diff path defects are fixed
- upload rejects unsafe zip entries before extraction
- invalid proposal IDs fail consistently
- viewing entity details cannot clear decisions when snapshots are missing
- resolver summaries are consistent across list, detail, preflight, and apply receipt
- preflight can explain the exact apply plan
- skipped entities and paths have explicit reasons
- successful apply writes a durable receipt before decision cleanup
- partial/full apply differences are visible to users and agents
- transfer packet warnings and metadata still render and remain machine-readable
- docs match current manifest names and storage paths
- targeted proposal tests pass

## Revision Safety Checklist

Do not start implementation until each item has an owner or a deliberate defer decision:

- Identify every route and CLI command that accepts `proposal_id`.
- Decide the strict proposal ID regex and migration policy for existing folders.
- Define the trusted diff source states.
- Define when decision pruning is allowed.
- Define the entity apply contract matrix in code comments or tests.
- Define the preflight response schema.
- Define receipt storage location and retention.
- Define how transfer packet preflight is nested inside generic proposal preflight.
- Define how resolver summaries are cached or recomputed.
- Define server-side confirmation compatibility period.
- Define rollback as deferred or in scope.

## Deferred Work

Defer these unless the minor release expands:

- dedicated proposal decision database tables
- official collection promotion workflow
- complete admin app rewrite
- cross-site resolver rule portability
- row-level merge UI for complex nested payloads
- broad media subsystem replacement

## Implementation Notes

Keep the first implementation slice boring. The fastest path to a better Proposal/diff system is to make the current contracts explicit, test the known weak paths, and provide a truthful preflight before apply. Larger storage and UI refactors become easier once the system can explain exactly what it is about to do.
