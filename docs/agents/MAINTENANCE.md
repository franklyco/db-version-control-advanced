# DBVC Agent Reference Maintenance

Use this guide only when a change affects the DBVC capability inventory or the agent-reference tooling. It does not make `docs/agents/` required startup context for unrelated work.

## What Triggers Maintenance

Run the maintenance flow when a change adds, removes, renames, or materially changes any of these surfaces:

- WP-CLI namespaces, commands, arguments, or behavior;
- REST routes or supported methods;
- admin menus, admin-post handlers, or AJAX actions;
- public DBVC actions or filters;
- `dbvc_*` settings and option ownership;
- durable DBVC tables or scheduled hooks;
- loaded add-ons or source-reference/runtime boundaries;
- status, safety, storage, authentication, backup, rollback, or live-verification claims in an existing record.
- reviewed opportunity disposition, priority, effort, recommended interface, rationale, or related-record coverage.

Private implementation refactors that do not change a capability surface still run the check when they touch a discovery-scanned path, but they normally need no manifest edit.

## Standard Change Flow

Agent-reference tooling requires PHP 8.1 or newer; this developer-tool requirement does not change the plugin's documented WordPress runtime requirement.

1. Make the scoped implementation or documentation change.
2. When source registrations or discovery-scanned files changed, run `composer agent-docs:discover` and inspect new, removed, or changed discovery IDs.
3. Update `manifest.json` ownership, status, safety, evidence, relationships, verification, and reviewed opportunity metadata when the capability record changed or new evidence was produced.
4. Update one relevant facet or long-form authority document only when operational guidance changed.
5. When discovery or the manifest changed, run `composer agent-docs:refresh` to rebuild the snapshot and every generated index.
6. Run `composer agent-docs:check` and review the generated diff before committing.
7. Complete the pull-request capability-impact section. Name affected record IDs and unresolved live-runtime verification.

### Per-capability verification update rule

After a capability—or a coherent same-capability test matrix—is processed, reviewed, checked, tested, or confirmed, update that capability's canonical `manifest.json` record before moving to an unrelated capability or risk boundary. Related cases may be recorded together when they share the applicable checkout, owner, authorization, runtime prerequisites, and safety boundary. A shared fixture lifecycle is required only when test data is needed. Do not split success, no-op, fail-closed, and accessibility cases into separate documentation boundaries unless a case exposes a defect that requires its own implementation decision.

Record the current `verified_date`, precise `evidence_types`, concise current outcome in `verification.notes`, applicable `test_refs`, and any changed warnings or known gaps. Set `live_runtime_verified=true` only when the complete bounded record—not merely one sub-operation—has same-checkout live confirmation. Partial, failed, blocked, or operation-level checks must still be recorded as scoped evidence or notes rather than omitted.

Run `composer agent-docs:refresh` once after the capability or matrix update so the administrator Capability Landscape reflects the current verification badge, date, evidence, tests, and notes. Do not defer results across unrelated capabilities, but do not regenerate the library after every row in the same approved matrix.

### Capability QA batching and recording

Use the smallest evidence structure that preserves current truth:

1. Confirm checkout provenance, applicable authorization and runtime prerequisites, and the bounded operations once at the start of the batch. Confirm fixture state only when the cases depend on test data.
2. Use one namespaced disposable fixture lifecycle for related cases when their ordering and mutations are isolated. Inspect authoritative state after each write-sensitive case and clean the complete fixture once at the end. Read-only or source-only checks do not require an invented fixture.
3. When runtime behavior was exercised, record one compact batch summary in `RUNTIME_VERIFICATION.md`, including cases attempted, pass/fail/blocked state, applicable writer boundaries and cleanup, and relevant test/build totals. Repository-only checks can remain in manifest verification notes and `test_refs`; do not create an empty runtime entry.
4. Update the manifest once with the batch's current evidence and gaps. Update a long-form implementation guide only when the run changes implementation meaning, discovers a defect, or closes an approved boundary.
5. Do not append routine run history to this maintenance guide or duplicate the same narrative across multiple documents.

### Capability-shaped evidence selection

There is no universal runner, fixture, browser, database, or writer requirement. Select only the evidence needed to support the capability claim being reviewed:

| Capability or claim | Minimum applicable evidence | Do not require or infer |
|---|---|---|
| Source registration or discovery | Registration/source reference plus focused static or contract coverage where available | Callable runtime behavior or live verification |
| Read-only CLI, REST, or service behavior | Exact command, route, or call; required role/environment; exit, response, or returned-state assertion | Browser QA or a fixture unless data is needed |
| Write-capable behavior | Explicitly approved target and operation; before/after authoritative state; bounded backup/rollback where supported; cleanup of disposable artifacts | Broader write authorization or safety beyond the tested operation |
| Administrator or interactive UI behavior | Authenticated applicable role; visible and interaction assertions; stored-state check when a write occurs or presentation can diverge from persistence | UI coverage for a non-UI capability |
| Conditional add-on or integration | Activation/gate prerequisites and the observed registered or absent state | Forced activation; absence may be the correct checked result |
| Generated manifest or documentation behavior | Discovery/manifest validation and generated-diff review | A runtime claim that the check did not exercise |

Use these as review questions, not as new manifest fields or command parameters: What exact claim is being tested? Which checkout and source own it? Which role, activation state, data, or external dependency is actually required? Can it write, and if so what exact target and recovery boundary were approved? What observable assertion proves the claim? Is an independent persisted-state check needed? What remains explicitly untested or blocked?

Batch only when the applicable answers remain shared. Split the work when capability ownership, checkout, authorization level, add-on activation, external dependency, or write/recovery scope changes. A failed case may become its own implementation boundary; a partial or blocked case remains valid recorded evidence and must not be promoted to complete verification. Existing verified cases need reruns only when a relevant source, runtime, dependency, or presentation change can invalidate their evidence.

Validation must match the change:

- source change: syntax/static checks, focused tests, required build, then the full affected regression suite once at batch close;
- runtime-evidence-only run: exact applicable CLI, API, service, UI, authoritative-state, and cleanup assertions; do not require unrelated evidence types, rebuild, or rerun unrelated source suites;
- manifest or agent-doc change: `composer agent-docs:refresh`, `composer agent-docs:check`, and `git diff --check`;
- prose-only guidance change with no manifest/discovery effect: `composer agent-docs:check` and `git diff --check` are sufficient.

Do not add a separate run-ledger schema while the manifest plus one compact runtime summary can represent the remaining work accurately. Reconsider a machine-readable run ledger only if multiple capabilities require recurring matrices that can no longer be summarized without ambiguity.

Do not edit files under `docs/agents/generated/` manually. `manifest.json` is curated authority; the discovery snapshot and Markdown indexes are replaceable derivatives.

`RECIPES.md` is a compact reviewed layer, not a generated command reference. Every recipe must keep its adjacent `recipe`, `safety`, and `capability-records` metadata comments valid. Phase 11 permits only `read_only` recipes, requires the `cli.core.capabilities.inspect` preflight record, and rejects unknown or duplicate record references during `agent-docs:check`.

## CI Contract

`.github/workflows/agent-docs.yml` runs the strict check on relevant pull requests, relevant pushes to `master`, and manual dispatch.

The check fails when:

- a discovered enforced surface has no manifest owner or reviewed ignore decision;
- the manifest maps an unknown or multiply owned discovery ID;
- a manifest source, test, or document reference no longer resolves;
- schema, taxonomy, relationship, safety, or verification contracts are invalid;
- the discovery snapshot, generated indexes, command signatures, or README summary are stale.

The snapshot uses a deterministic fingerprint of discovery-scanned source files. It intentionally does not embed `HEAD`, branch, or commit time because that would make a committed generated file self-invalidating.

## Capability-Impact Review

For every public-surface change, record:

- the added, removed, renamed, or behaviorally changed surface;
- owning manifest record IDs;
- status and safety consequence;
- data stores and external systems touched;
- authentication, preview/dry-run, backup, rollback, and idempotency implications;
- applicable tests and long-form documentation;
- whether the same checkout was verified live.

Mechanical discovery may expose an unclassified surface, but it must not automatically assign `active`, safe, supported, or live-verified status.

Likewise, REST-without-CLI is only a review prompt. Record a concrete `opportunity` object before presenting a surface as a candidate, covered elsewhere, deferred, or not recommended.

## Review Cadence

At each release candidate—or at least once every 90 days when releases are less frequent—review non-stable records and checkout boundaries with:

```bash
composer agent-docs:query -- status:unknown_requires_verification
composer agent-docs:query -- status:experimental
composer agent-docs:query -- status:planned
composer agent-docs:query -- status:absent_current_checkout
```

For each result, either reaffirm its status and verification notes, promote/demote it with current evidence, or record why no change is justified. Recheck `source_reference` records whenever bootstrap loading or add-on isolation changes.

Live verification must load the same checkout represented by the manifest before `live_runtime_verified` becomes true. Evidence from another checkout belongs in a comparison report, not in repository-active claims.

## Release Check

Before a release candidate or tagged release that changes DBVC surfaces:

```bash
composer agent-docs:refresh
composer agent-docs:check
git diff --check
```

Commit all intentional generated changes together with the curated manifest change. A clean check should never require hand-editing multiple indexes.

If agent-reference files are newly added, also confirm they are tracked and present in the actual release artifact. Absence of `export-ignore` or ignore rules permits packaging but does not make untracked files part of `git archive` or a release ZIP. Record same-checkout runtime and package evidence in [`RUNTIME_VERIFICATION.md`](RUNTIME_VERIFICATION.md) instead of promoting the entire manifest baseline from registration checks alone.

## Current Implementation Boundary

Phase 26 adds a WordPress REST authorization fixture for the bounded administrator operator surface: the registered prune route returns `rest_forbidden` without option mutation for a subscriber, then returns the exact pruned entity/proposal summaries for an administrator. Authenticated local browser QA subsequently displayed the eligible drawer control and exact confirmation copy against a disposable trusted-snapshot fixture. The same registered writer removed two stale fixture choices, preserved one valid choice on its no-op path, and failed closed with `409 dbvc_decision_pruning_unavailable` while preserving two choices after the fixture snapshot was removed. Browser-control native-confirmation handling prevented a complete toast/error-refresh assertion, and a later drawer reload showed stale fixture state; therefore this remains scoped runtime evidence rather than full live-runtime verification. The fixture was removed completely. Resolver calls with `dry_run=true` no longer backfill `vf_asset_uid`/`vf_file_hash`, existing media-bundle lookup no longer creates or hardens storage, snapshot read/metadata lookup no longer creates or hardens absent snapshot storage, backup-base lookup for proposal-list and manifest/payload readers no longer creates or hardens absent backup storage, post/term current-state snapshot inspection no longer assigns stable UIDs or synchronizes identity records, and single-entity detail preserves decisions. Capture, staging, and other writer paths remain explicit writers. `cli.proposals.inspect` still avoids live resolver matching and detail callbacks to preserve its bounded structural contract. Content Migration readiness remains excluded because its current GET callback can write QA reports; use `cli.content_migration.runs.inspect` only for existing run artifacts.

## Proposal Decision Operator Browser UI Refresh QA — 2026-08-12

Phase 27 hardens the shared Proposal Review GET helper with `cache: "no-store"`. During same-checkout authenticated fixture follow-up, the browser still displayed a previously removed namespaced fixture and its `Prune stale decisions` action while the active database, fixture option, pages, proposal directory, and snapshot directory were all absent. Reloading after the fresh-read change returned zero fixture rows and zero prune actions. This verifies that the observed residual presentation was stale browser/client state, not a remaining decision or DBVC artifact.

The registered writer's earlier success, no-op, authorization, and fail-closed evidence remains scoped. Phase 28's in-app modal removed the native-confirmation blocker, Phase 29 verified its returned 409 copy, Phase 30 verified its persistent successful-prune status, and Phase 32 repaired and verified modal focus management in the authenticated local browser. `proposal.core.decisions` remains not globally live-runtime verified because broader decision writers remain intentionally out of scope.

## Proposal Decision Operator Fail-Closed Error UI QA — 2026-08-12

Phase 29 opened the eligible in-app confirmation for an isolated, namespaced fixture, removed only that fixture's trusted snapshot before confirmation, and then confirmed the request. The drawer visibly rendered `Stale decisions can be pruned only after a trusted current-state snapshot is available.` while still displaying `1 accepted · 1 kept`. Fixture inspection independently confirmed both stored choices remained, and cleanup left no fixture posts, decision option entries, proposal/snapshot directories, or backup directories.

Phase 30 completes the persistent success-status browser check below; the next outstanding boundary is the modal keyboard accessibility check.

## Proposal Decision Operator Persistent Success Status UI QA — 2026-08-12

Phase 30 opened the same in-app confirmation for a fresh isolated stale fixture and confirmed the successful writer path. After the authoritative refresh, the drawer showed `Stale decisions pruned` and `2 stale choices were removed; 0 current choices remain.` The pruning control was no longer available and the drawer showed no selections. Fixture inspection independently confirmed the success entity's two decisions were removed while its unrelated fixture records remained, and cleanup then removed every fixture artifact.

Phase 31 completed the modal keyboard QA below and identified the focused restoration defect now carried as the next implementation boundary.

## Proposal Decision Operator Modal Keyboard Accessibility QA — 2026-08-12

Phase 31 used a fresh isolated fixture and opened the in-app confirmation without confirming its writer. Keyboard Escape dismissed the modal and preserved both eligible choices and the visible prune action; fixture inspection confirmed no decision mutation. However, browser focus evidence showed the initial focus remained on the active entity-list control outside the modal, then Escape returned focus to the drawer's `Close` control rather than the initiating `Prune stale decisions` control. The fixture was removed completely.

This was a documented accessibility defect, not an implementation change; Phase 32 resolves it below without altering the prune writer, readiness, or apply behavior.

## Proposal Decision Operator Modal Focus Restoration Fix — 2026-08-13

Phase 32 separates drawer lifecycle focus from modal focus, retains the prune opener in a dedicated ref, moves opening focus to `Cancel`, and restores the prune opener after Cancel, Escape, or the WordPress modal Close control. Focused source-contract coverage passed, and authenticated fixture QA verified each dismissal path restores `Prune stale decisions` while retaining both fixture decisions. Cleanup removed every fixture artifact.

The remaining Proposal Decision Operator UI closeout is the **drawer-close focus regression**. Run it as one non-writer case under the batching policy above, record one compact closeout result, and do not rerun the already verified success/no-op/409/modal cases unless relevant source changes invalidate them.
