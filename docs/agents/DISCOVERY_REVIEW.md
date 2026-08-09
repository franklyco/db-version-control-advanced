# DBVC Agent Reference Discovery Review

## Current LocalWP Reconciliation — 2026-08-06

This file preserves the original detached-worktree research below as provenance. It is not the current inventory authority.

The agent library is now merged into the active LocalWP plugin checkout at HEAD `78a06ad6429574bc1ac88158ec7c037b257dd3a6`, with unrelated dirty work preserved. Current static discovery reports:

- 47 curated records;
- 393 strictly enforced capability surfaces;
- 13 WP-CLI leaf commands;
- 126 REST registrations;
- 49 literal `dbvc_*` settings;
- 144 DBVC extension-point occurrences;
- zero ignored or unmapped enforced surfaces.

The LocalWP reconciliation reclassified Visual Editor and Content Migration as active source-loaded capability families, added media hydration, configuration portability, and AI package intake records, and migrated line/offset-derived ownership to this checkout's current discovery IDs.

Use `manifest.json`, the generated indexes, and `composer agent-docs:check` for current authority. Same-checkout authenticated WP-CLI, REST, and browser verification remains pending and no record is marked `live_runtime_verified: true`.

## Original Detached-Worktree Review — Historical

Baseline commit: `0e8233c35df0485dec14adf33f0a064b71480c2d`  
Checkout state: Detached HEAD; repository-only discovery  
Live LocalWP verified: No  
Manifest state: `review_pending`; 43 curated records; strict coverage

## Purpose

This began as the point-in-time bridge between mechanical source discovery and the curated initial manifest. Its groupings provide historical provenance only; the current LocalWP manifest supersedes its counts and checkout-absence claims.

The canonical raw evidence is `generated/discovery-snapshot.json`.

## Reconciled Discovery Counts

| Collection | Observed | Independent reconciliation |
|---|---:|---|
| WP-CLI namespaces | 3 | Registration search: 3 |
| WP-CLI leaf commands | 10 | Public registered command methods: 10 |
| Core REST registrations | 38 | Direct registration search: 38 |
| Bricks REST registrations | 36 | Direct registration search: 36 |
| Content Collector source-reference REST registrations | 12 | Direct registration search: 12 |
| Literal `dbvc_*` settings keys | 43 | Independent literal option-call search: 43 |
| DBVC extension-point occurrences | 96 | Direct `do_action`/`apply_filters` search: 96 |
| Hook listener registrations | 80 | Direct `add_action`/`add_filter` search: 80 |
| Admin/AJAX handlers | 10 | Filtered from listener registrations |
| Admin menu/submenu registrations | 5 | Direct registration search: 5 |
| Database tables | 8 | DBVC database table map: 8 |
| Scheduled-hook calls | 3 | Direct scheduling-function search: 3 |
| Current PHPUnit files | 22 | Repository test-file inventory: 22 |

The full snapshot exposes 261 unique surfaces to coverage reconciliation. Phase 3 maps all 261 under strict enforcement; no discovery items are currently ignored.

## Runtime Boundary Observed From Source

- Core services, the Entity Editor, and Bricks add-on files are included from the plugin bootstrap in this checkout.
- Content Collector files under `_source/content-collector/` are discovered only as source-reference material.
- The snapshot does not prove plugin activation or live route availability on a LocalWP site.
- Visual Editor is not represented as a runtime surface in this checkout and should be researched only as an `absent_current_checkout` candidate if historical cross-checkout context is intentionally included.

## Proposed Initial Record Groupings

These were the approved research buckets. Final record IDs, status, safety, evidence, and relationships are in `manifest.json`.

### 1. Core CLI And Automation

- core export command;
- core import command;
- snapshot listing command;
- proposal list/upload/apply commands;
- resolver-rule list/add/delete/import commands;
- shared CLI registration and error/output conventions.

Read-only and write commands should remain separate records even when they share a namespace.

### 2. Core Import And Export

- post/CPT export and import;
- taxonomy/term export and import;
- options, ACF options groups, menus, and FSE artifacts;
- full, batch, differential, and chunked modes;
- sync upload routing and dry-run intake;
- filename, path, masking, mirror-domain, and media-bundle behavior.

CLI surfaces should link to these engine capabilities rather than duplicate their implementation contracts.

### 3. Proposal Review And Apply

- proposal listing, upload, deletion, and status;
- entity listing/detail and Accept/Keep decisions;
- duplicate discovery and cleanup;
- new-entity gating;
- entity/proposal snapshots and hash synchronization;
- masking inspection/apply/revert;
- apply readiness and full/partial apply;
- maintenance cleanup and client error logging.

Read-only inspection, decision writes, destructive cleanup, and final apply should not be collapsed into one safety classification.

### 4. Media And Resolver

- media indexing and manifest preview;
- bundle creation and transport modes;
- resolver metrics and proposal decisions;
- global resolver-rule management;
- reconciliation/download behavior;
- cache clearing and media cleanup.

### 5. Identity, Snapshots, Storage, And Observability

- stable post/term/media identity and entity registry;
- eight DBVC database tables, grouped by identity, history/jobs, media, and collections;
- snapshot and backup manifests;
- activity/file logging;
- scheduled FTP-window expiration;
- Official Collections scaffolding, with status determined separately from the loaded storage helper.

### 6. Entity Editor

- entity index and rebuild;
- file inspection;
- file save;
- partial import;
- full replacement import;
- individual and bulk downloads;
- protected metadata and provider boundaries.

Inspection and replacement operations require separate risk treatment.

### 7. Settings And Extension Points

- import/export selection and filename settings;
- paths and temporary FTP window;
- proposal enforcement and new-entity behavior;
- masking settings;
- media settings;
- logging settings;
- ACF options-group settings;
- supported public hooks/filters grouped by import/export, lifecycle, media, Entity Editor, and Bricks ownership.

The 96 extension-point occurrences should be consolidated by contract rather than converted into 96 shallow capability records.

### 8. Bricks Add-on

- status, schema, UI contract, diagnostics, and telemetry;
- artifact collection and canonicalization;
- drift scan and raw comparison;
- configuration and shared-rules distribution;
- apply, restore points, and rollback;
- Bricks proposals;
- packages, bootstrap creation, pull, promotion, revocation, acknowledgement, and remote publishing;
- connected-site identity and alias/linkage operations;
- intro/onboarding handshake and reset/rerun;
- signed command enqueue, pull, acknowledgement, status, and ping.

The Bricks REST surface should be grouped by workflow and authorization boundary. It should not become one record per route by default.

### 9. Source-Reference, Planned, And Checkout-Absent Work

- Content Collector/Content Migration source reference;
- Content Collector explorer, AI, and export route families as non-runtime evidence;
- Official Collections future UI/API/CLI layers;
- roadmap-only canonical authority and granular options work;
- Visual Editor as a checkout-absent candidate only if cross-checkout discovery is approved.

These records must be excluded from active-capability queries unless later runtime evidence changes their status.

## Phase 3 Research Order Used

1. Core CLI plus import/export engines.
2. Proposal lifecycle, masking, media, and apply safety.
3. Identity, snapshots, storage, settings, and hooks.
4. Entity Editor.
5. Bricks add-on.
6. Source-reference, planned, and checkout-absent classifications.

This order establishes the shared engine and safety records before route-heavy add-on records link to them.

## Known Static-Discovery Limitations

- Dynamically constructed option keys, route fragments, hook names, table names, and callbacks require manual inspection.
- Settings held only in class constants are not guaranteed to appear in the literal 43-key inventory.
- Source registration does not prove WordPress capability checks, nonce handling, idempotency, rollback, or live availability.
- Documentation and tests provide evidence but cannot independently promote a capability to `active`.
- Live runtime comparison remains a later, separately authorized verification step.

## Review Gate

Phases 1 through 3 now pass the repository-only boundary: the manifest is `review_pending`, all 261 enforced surfaces are mapped, and generated indexes are current. Live command/route reconciliation remains a later Phase 6 boundary and is not implied by this review.
