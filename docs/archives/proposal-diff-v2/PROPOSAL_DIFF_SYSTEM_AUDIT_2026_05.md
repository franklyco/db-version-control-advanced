# DBVC Proposal and Diff System Audit

Date: 2026-05-23

Scope: core DBVC proposal upload, proposal listing, entity diff review, resolver decisions, masking, partial/full apply, CLI proposal helpers, and related documentation.

This audit intentionally excludes the Visual Editor add-on, Bricks portability work, AI packages, and Content Collector behavior except where naming or workflow overlap affects the core Proposal/diff system.

No code changes were made as part of this audit.

## Executive Summary

The core Proposal/diff system is functional and has a clear end-to-end path:

1. Build or upload a proposal bundle with `manifest.json`, entity payloads, media index, and optional media bundle assets.
2. Store the proposal under the DBVC backup/proposal storage root.
3. Capture live snapshots for supported current-site entities.
4. Present per-entity diffs and decision controls in the admin app.
5. Store path-level decisions in option-backed state.
6. Apply accepted decisions through the import path.

The main risk is not missing features. The main risk is that review, decision, diff, resolver, masking, apply, and proposal maintenance behavior are concentrated in a few large classes and option blobs. That makes the system harder for humans and agents to inspect, test, recover, and safely extend.

The recommended minor update should prioritize correctness and workflow clarity before new feature breadth:

- Fix high-confidence defects in CLI duplicate cleanup, term masking, and bulk diff path generation.
- Add a real proposal preflight/dry-run report before apply.
- Make skip reasons, stale decisions, missing snapshots, and resolver state visible to users and agents.
- Normalize docs around actual manifest names and storage paths.
- Start extracting proposal repository, diff, decision, upload validation, and preflight services behind existing REST contracts.
- Add targeted tests around terms, non-post entities, bulk accept/unaccept, masking, upload safety, and CLI output.

## Current System Map

### Storage and Bundle Intake

- Primary proposal storage is managed through `includes/class-backup-manager.php`.
- The current proposal format uses `manifest.json`, `entities.jsonl`, staged entity JSON payloads, `media_index.json`, and optional `media_bundle/` assets.
- Uploaded proposals are handled by `DBVC_Admin_App::upload_proposal()` and `DBVC_Admin_App::import_proposal_from_zip()`.
- Proposal directories are stored under the backup base path returned by `DBVC_Backup_Manager::get_base_path()`.
- Media bundle files are managed separately through `includes/Dbvc/Media/BundleManager.php`.

### Admin REST and Review Workflow

- `admin/class-admin-app.php` owns most proposal REST routes and business logic.
- Key route families include:
  - proposal list, upload, delete, and fixtures
  - entity list and entity detail
  - entity decisions and bulk decisions
  - masking directives
  - snapshot capture and refresh
  - media resolver decisions and resolver rules
  - apply, status, maintenance, and logs
- The admin app frontend lives mainly in `src/admin-app/index.js`.

### Diff and Snapshot Behavior

- Live current-state snapshots are managed by `includes/class-snapshot-manager.php`.
- Entity detail diffs are built by comparing the proposed payload with the live snapshot when available.
- The private diff helpers in `DBVC_Admin_App` flatten payloads, ignore volatile paths, classify sections, and summarize path counts.
- If no live snapshot exists, current behavior often falls back to comparing the proposed payload to itself. That prevents noisy failures but can hide field-level differences.

### Decision State

- Path-level proposal decisions are stored in the `dbvc_proposal_decisions` option.
- Resolver decisions are stored in the `dbvc_resolver_decisions` option.
- Proposal status and summary metadata are stored alongside per-entity decision state under the same proposal-level option tree.
- Accepted, kept, rejected, and skipped decisions are consumed by `includes/class-sync-posts.php` during apply.

### Apply Path

- `DBVC_Admin_App::apply_proposal()` calls `DBVC_Sync_Posts::import_backup()`.
- Post/page/CPT payload writes are handled by `DBVC_Sync_Posts::import_post_from_json()`.
- Term writes are handled by `DBVC_Sync_Posts::apply_term_entity()`.
- Options, option groups, menus, third-party items, and terms are supported through separate import branches.
- Proposal masking is applied during import through masking helpers and proposal-level masking directives.

### Media Resolution

- Media resolution and bundle state are handled by:
  - `includes/Dbvc/Media/Resolver.php`
  - `includes/Dbvc/Media/Reconciler.php`
  - `includes/Dbvc/Media/BundleManager.php`
- The proposal list and entity list re-run resolver summaries so reviewers can see attachment and bundle status.

### CLI

- Proposal CLI behavior lives in `commands/class-wp-cli-commands.php`.
- The CLI can list proposals, inspect status, and run duplicate cleanup flows.
- CLI behavior is useful but is not yet agent-first: it has limited structured output, limited dry-run detail, and at least one direct coupling to a private admin-app constant.

## Findings Chart

Severity key:

- P0: likely broken, unsafe, or blocks reliable workflow.
- P1: correctness or review-safety issue that should be fixed in the next minor update.
- P2: workflow, maintainability, performance, or agent ergonomics improvement.
- P3: cleanup or documentation clarity.

| ID | Severity | Area | Finding | Evidence | Impact | Proposed Direction |
| --- | --- | --- | --- | --- | --- | --- |
| F01 | P0 | CLI maintenance | Duplicate cleanup CLI references a private admin-app class constant. | `commands/class-wp-cli-commands.php` reads `DBVC_Admin_App::DUPLICATE_BULK_CONFIRM_PHRASE`, while the constant is private in `admin/class-admin-app.php`. | `wp dbvc proposals list --cleanup-duplicates` can fatal when the confirmation path is reached. | Move the confirmation phrase behind a public helper, make the constant public with intent, or duplicate the literal in the CLI with tests. |
| F02 | P0 | Term apply and masking | Term masking override handling references an out-of-scope variable. | `DBVC_Sync_Posts::apply_term_entity()` references `$proposal_mask_overrides` without receiving it as a parameter. | Term proposal masking may not work and can emit runtime warnings during term import. | Pass proposal mask directives into the term apply path and add term masking tests. |
| F03 | P0 | Bulk decisions | Bulk diff path fallback compares proposed payload to itself. | `DBVC_Admin_App::resolve_entity_diff_paths()` falls back to `compare_snapshots($proposed, $proposed)`. | Bulk accept/unaccept can stamp no field decisions when snapshots are absent or when the current diff path set is empty. | Replace the no-op fallback with a real proposed/current comparison strategy or explicit whole-entity decision behavior. |
| F04 | P1 | Apply safety | Full apply can process some non-post entities without path-level review decisions. | `DBVC_Sync_Posts::import_backup()` targets all manifest items in full mode, while options, option groups, menus, and some third-party branches do not mirror post path-decision gating. | A reviewer may expect accepted paths only, but broad apply may update high-impact non-post state. | Add preflight warnings and require whole-entity or path-level acceptance for non-post types where possible. |
| F05 | P1 | Diff review | Missing snapshots can hide field-level differences. | Entity detail uses snapshot current state when available and proposed payload as fallback when not available. | Users and agents can see "needs review" or hash mismatch without actionable field diffs. | Add a visible "snapshot missing" state, recapture affordance, and deterministic fallback behavior. |
| F06 | P1 | Upload safety | Zip entries are extracted before explicit path preflight. | `import_proposal_from_zip()` extracts via `ZipArchive::extractTo()` and validates manifest asset paths after extraction. | Zip-slip behavior depends on platform/PHP safeguards and should not be assumed. | Pre-scan every zip entry and reject absolute paths, traversal, directory escape names, and unsafe filenames before extraction. |
| F07 | P1 | Proposal identity | Proposal IDs are sanitized inconsistently. | Some route handlers use route regex and `sanitize_text_field()`, while storage reads concatenate proposal IDs into filesystem paths. | A malformed ID may resolve unexpectedly or produce confusing errors. | Add a single proposal ID normalizer that only permits known-safe directory names and use it in every route and CLI path. |
| F08 | P1 | Partial apply | Partial import mode effectively focuses on post items and skips most non-post items. | `DBVC_Sync_Posts::import_backup()` sets targets from accepted post UIDs in partial mode, with special handling for `third_party`. | Accepted term, option, menu, or option-group decisions may not apply in partial mode. | Make partial mode type-aware, or label non-post decisions as requiring full apply until supported. |
| F09 | P1 | Decision durability | Auto-clear removes the active decision state after success. | `auto_clear_decisions` clears proposal decisions after a successful import. | The final applied decision set is not preserved in an obvious durable receipt. | Before clearing, write a proposal apply receipt with decisions, hashes, skips, resolver choices, and import results. |
| F10 | P2 | Decision storage | Decisions, status, and summary are mixed in a single option tree. | `dbvc_proposal_decisions` stores entity decisions and `__summary`. | Harder to audit, diff, migrate, lock, or repair. Large proposals can create large option writes. | Introduce a decision-store service first; consider a dedicated table or per-proposal JSON receipt later. |
| F11 | P2 | Stale client behavior | Empty decision path/action returns a soft success. | `update_entity_decision()` treats empty path/action as a stale decision request and returns success. | UI avoids noisy errors, but agents cannot distinguish a real write from a skipped stale request. | Return `skipped: true` with a machine-readable reason while keeping HTTP success if needed. |
| F12 | P2 | Performance | Proposal and entity lists repeatedly read manifests, snapshots, payloads, and resolver state. | `get_proposals()` and `get_proposal_entities()` compute summaries on demand. | Large proposals may make list/detail views slow and harder for agents to batch inspect. | Add a per-proposal index/cache keyed by manifest checksum, snapshot mtime, resolver rule version, and decision revision. |
| F13 | P2 | Media workflow | Resolver and legacy media sync paths can both participate in apply. | `DBVC_Sync_Posts::import_backup()` enqueues `Dbvc\Media\Reconciler` and later can call `DBVC_Media_Sync::sync_manifest_media()`. | Reviewers may see duplicated concepts and agents may not know which result is authoritative. | Define one authoritative proposal media reconciliation report and mark legacy sync as compatibility behavior. |
| F14 | P2 | Resolver decisions | Resolver decisions are keyed around proposal attachment IDs and proposal-specific paths. | Resolver decision options use proposal ID and original attachment ID context. | Decisions may be hard to reuse across environments if attachment IDs differ. | Prefer stable `asset_uid`, file hash, source URL, or manifest identity where available. |
| F15 | P2 | UI maintainability | The admin app source is large and proposal UI state is not isolated cleanly. | `src/admin-app/index.js` is a broad monolith; `docs/admin-app-refactor-plan.md` already identifies this issue. | Human and agent edits are slower, riskier, and harder to review. | Extract proposal API, decision state, resolver state, and diff drawer UI into focused modules after backend fixes. |
| F16 | P3 | Documentation | Public docs disagree with current bundle names and paths. | `README.md` references `dbvc-manifest.json` and `uploads/dbvc/...`; code uses `manifest.json` and the sync backup/media-bundle paths. | Users and agents may build invalid bundles or inspect the wrong directories. | Update docs after the minor update scope is accepted. |
| F17 | P3 | Official collections | Official collection storage exists but is not integrated into proposal apply UX. | `includes/Dbvc/Official/Collections.php` is present; docs mark more lifecycle work as future. | Successful proposals do not yet become a first-class official baseline through the review UI. | Keep out of the first stabilization pass unless the minor version explicitly includes official-release workflow. |
| F18 | P3 | Naming overlap | Bricks proposal code and core DBVC proposal review share terminology. | `addons/bricks/bricks-proposals.php` uses "proposal" language but is not the same system. | Agents can inspect or change the wrong module from broad prompts. | Add a terminology note in proposal docs: "core content proposal" versus "Bricks global-style proposal." |

## Workflow Gaps for Human Users

### Apply Preview Is Too Implicit

Users can review diffs and accept paths, but there is no single preflight report that answers:

- Which entities will be created, updated, skipped, or blocked?
- Which accepted decisions have no current diff path?
- Which entities have missing snapshots?
- Which non-post entities require full apply?
- Which media files will be reused, downloaded, attached, or left unresolved?
- Which masking directives will be applied?
- Which decisions will be cleared after success?

Recommendation: add a proposal preflight endpoint and UI panel before changing apply behavior.

### Missing Snapshot State Needs First-Class UI

The current fallback avoids hard failure, but it can make a proposal look reviewed while hiding the actual difference. This is especially risky for:

- imported proposals created outside the current site
- term proposals
- older proposal bundles
- proposals after local content has changed
- proposals with new entity identity collisions

Recommendation: show "snapshot missing" or "snapshot stale" as a visible diff state and make recapture explicit.

### Partial Apply Semantics Are Narrower Than They Look

Partial mode is safest for posts, but it is not obviously equivalent for terms, options, menus, option groups, or third-party entities. The UI and CLI should avoid implying that all accepted paths across all types will apply in partial mode.

Recommendation: make apply mode eligibility part of the preflight result.

### Duplicate Cleanup Has Two Different Personalities

Upload validation rejects duplicate manifest entities, while existing proposal maintenance offers duplicate cleanup. Both are useful, but their relationship is not obvious.

Recommendation: document duplicate handling as:

- upload-time duplicate rejection for new proposal bundles
- maintenance cleanup for legacy or generated proposal folders already on disk

## Workflow Gaps for Agent Users

### No Stable Machine-Readable Preflight

Agents need deterministic JSON that can be inspected before apply. The system should expose:

- proposal metadata
- entity counts by type and status
- accepted/rejected/kept/skipped path counts
- snapshot availability
- resolver state
- blocking errors and warnings
- exact import plan

Recommendation: add REST and WP-CLI preflight commands with JSON output.

### Skip Reasons Are Not Always Explicit

Some skipped behavior is logged internally or inferred from counts. Agents need direct reasons:

- missing decision
- rejected new entity
- missing snapshot
- unresolved media
- unsupported partial apply type
- stale path
- masked value
- resolver mismatch

Recommendation: make skip reasons part of entity summaries and apply receipts.

### Decision State Is Hard To Export, Compare, and Reapply

The option-backed decision store is easy for the UI, but agents need to export, review, and possibly reapply decisions across proposal refreshes.

Recommendation: add decision export/import or decision receipt commands before considering schema changes.

## Testing Gaps

Existing tests cover important packet and endpoint paths, including transfer packet validation, entity editor behavior, and masking endpoints. The next minor update should add focused tests for:

- CLI duplicate cleanup confirmation path.
- Term masking directives during apply.
- Bulk accept/unaccept path generation when snapshots are present, absent, or stale.
- Partial apply behavior for terms and other non-post entity types.
- Full apply gating for options, menus, option groups, and third-party entities.
- Zip entry path traversal rejection before extraction.
- Proposal ID normalization and rejection.
- Resolver decision reuse based on stable media identity.
- Apply receipt persistence before auto-clear.
- Agent-facing JSON output for preflight and CLI commands.

## Second-Pass Reconciliation Against Existing Plans

This section compares the audit findings with existing enhancement and architecture docs so the minor update does not collide with work already planned elsewhere.

### Configuration Portability Is The Best Safety Model

`docs/DBVC_CONFIGURATION_PORTABILITY_TOOL_IMPLEMENTATION_GUIDE.md` already describes and partially implements a stronger import model:

- safe zip extraction before trust
- staged import sessions
- validation before diff generation
- compatibility warnings
- apply preflight
- provider-level sanitization
- backup before first write
- rollback
- partial failure reporting

The core Proposal/diff update should reuse these principles. It should not create a second safety vocabulary.

Planning impact:

- Add proposal preflight before apply.
- Add durable apply receipts.
- Add explicit skip and block reasons.
- Add safe extraction parity.
- Consider rollback only after receipts and pre-apply snapshots are reliable.

### Cross-Site Transfer Packets Already Depend On Proposal Review

`docs/CROSS_SITE_ENTITY_PACKET_IMPLEMENTATION_GUIDE.md` intentionally routes destination-side packet intake through the existing proposal upload, review, resolver, and apply flow. Transfer packets also already surface `origin`, `selection`, `requirements`, `warnings`, and a transfer-specific `preflight` payload.

Planning impact:

- Do not replace transfer preflight fields.
- General proposal preflight must wrap or extend transfer packet warnings, not overwrite them.
- Preserve additive manifest metadata.
- Preserve new-entity gating for transfer packets.
- Include transfer packets in proposal preflight tests.

### Media Sync Docs Confirm Resolver Importance But Are Partly Stale

`docs/media-sync-design.md` describes resolver decisions, media bundle transport, and blocking conflict overlays. It also still references `dbvc-manifest.json` in places while the active proposal importer expects `manifest.json`.

Planning impact:

- The plan should treat resolver output as part of preflight.
- Docs must be corrected after behavior is locked.
- A single media plan should be authoritative before apply.
- The plan should not leave users guessing between resolver, reconciler, and legacy media sync output.

### Admin App Refactor Should Stay Separate

`docs/admin-app-refactor-plan.md` correctly identifies the React admin app monolith. That refactor is valuable, but coupling it to backend correctness fixes would increase release risk.

Planning impact:

- Backend service boundaries should land first.
- UI should consume new preflight/status fields with minimal current-bundle changes.
- The larger frontend source refactor can follow behind a feature flag.

### Universal Upload And Legacy Upload Plans Must Not Be Collapsed

`docs/ROADMAP.md` and `docs/legacy-upload-immediate-import-plan.md` preserve multiple upload paths:

- legacy sync-folder upload
- targeted immediate post import
- proposal ZIP review
- transfer-packet upload
- future universal intake

Planning impact:

- Do not merge these flows as part of the Proposal/diff stabilization release.
- The minor update should improve proposal review/apply contracts only.
- Universal intake can later route into proposal preflight when review is desired.

### Masking Docs Confirm The Intended Reviewer Contract

`docs/meta-masking.md` expects proposal masking decisions to affect posts, terms, and media labels inside review. The code path for post masking is covered better than the term apply path.

Planning impact:

- Term masking must be fixed before claiming term parity.
- Masking must be represented in preflight and apply receipts.
- Reverting masking decisions should remain available and test-covered.

## Additional Second-Pass Findings

| ID | Severity | Area | Finding | Evidence | Impact | Proposed Direction |
| --- | --- | --- | --- | --- | --- | --- |
| F19 | P0 | Decision safety | Opening an entity drawer can prune stored decisions to empty when no valid diff paths are available. | `get_proposal_entity()` falls back to proposed data as current, builds an empty diff, then calls `prune_entity_decisions_for_paths()`. | Missing or stale snapshots can erase reviewer selections just by viewing an entity. | Only prune when a trusted snapshot/current source exists, or when preflight confirms the path set is authoritative. |
| F20 | P0 | Path safety | A strict proposal ID sanitizer exists but is not used consistently by all routes, CLI helpers, and import/apply paths. | `sanitize_proposal_id()` is used in upload/delete, while several handlers and `DBVC_Sync_Posts::import_backup()` use `sanitize_text_field()`. | Filesystem reads/writes can resolve differently across paths and are harder to reason about. | Move strict proposal ID validation into a repository/helper used everywhere, including `DBVC_Backup_Manager`. |
| F21 | P1 | Resolver consistency | Resolver context differs between proposal list, entity list, and apply fallback paths. | `get_proposals()` passes proposal ID, bundle metadata, and manifest dir; `get_proposal_entities()` omits that context. | Media statuses and bundle previews can diverge between screens. | Create one resolver summary builder used by list, detail, preflight, and apply. |
| F22 | P1 | Apply confirmation | Apply is protected by capability checks and UI confirmation, but the REST apply route does not require a server-side preflight token or explicit confirmation field. | `apply_proposal()` accepts mode flags and proceeds through import. | Agents or custom clients can bypass the intended review confirmation semantics. | Add an additive preflight token/confirm field first, then decide whether to require it in a later release. |
| F23 | P1 | Transfer compatibility | Generic proposal preflight could accidentally duplicate or hide transfer-packet warnings if not designed carefully. | Transfer packets already expose `preflight`, `warnings`, `origin`, `selection`, and `requirements`. | Transfer review could regress while stabilizing core proposals. | Treat transfer preflight as an input to generic proposal preflight and preserve existing fields. |
| F24 | P2 | Rollback model | Core proposal apply has snapshots and logs, but no first-class apply-session backup/rollback equivalent to configuration portability. | Configuration portability captures backups before writes; proposal apply currently returns import results and may clear decisions. | Failed or partial applies are harder to recover or explain. | Start with apply receipts and pre-apply current snapshots; defer full rollback until write scopes are fully modeled. |
| F25 | P2 | Debug noise | Some import/export paths still emit raw `error_log()` diagnostics and legacy comments near proposal-critical code. | `import_post_from_json()` and export helpers contain direct debugging logs around Bricks/backslash handling. | Proposal apply logs can be noisy and harder for agents to parse. | Move proposal-relevant diagnostics to structured logger paths and leave low-level debugging behind explicit flags. |

## Recommended Minor Update Scope

### Must Fix First

- F01 duplicate cleanup CLI fatal risk.
- F02 term masking override bug.
- F03 no-op bulk diff fallback.
- F06 zip entry preflight.
- F07 proposal ID normalization.
- F19 decision pruning without a trusted diff source.
- F20 strict proposal ID normalization across all entry points.

### Should Fix In Same Minor

- Add preflight/dry-run endpoint and CLI output.
- Add visible missing-snapshot/stale-snapshot state.
- Add durable apply receipt before decision auto-clear.
- Clarify partial versus full apply semantics for non-post entities.
- Unify resolver context between proposal list, entity list, detail, preflight, and apply.
- Preserve transfer packet `origin`, `selection`, `requirements`, `warnings`, and preflight fields.
- Update docs for `manifest.json` and current storage paths.

### Can Defer

- Dedicated decision database table.
- Official collection promotion flow.
- Full admin app modular rewrite.
- Resolver rule portability overhaul.
- Large proposal materialized diff cache, unless performance becomes the driver for the minor release.

## Open Questions

1. Should non-post entities require explicit whole-entity acceptance before full apply?
2. Should partial apply support accepted term paths now, or should the UI block partial apply for term-only decisions?
3. Should successful apply always preserve a receipt even when decisions are not auto-cleared?
4. Should resolver decisions be proposal-local only, or should stable media identities create reusable global rules?
5. Should core proposal docs standardize on "proposal bundle" for portable imports and reserve "backup" for local export snapshots?

## Bottom Line

The Proposal/diff system is close to being reliable enough for agent-assisted review, but it needs clearer contracts around what will change, why items are skipped, and what evidence remains after apply. The best minor update is a stabilization and workflow release, not a feature expansion release.
