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
2. Run `composer agent-docs:discover` and inspect new, removed, or changed discovery IDs.
3. Update `manifest.json` ownership, status, safety, evidence, relationships, verification, and reviewed opportunity metadata as applicable.
4. Update one relevant facet or long-form authority document only when operational guidance changed.
5. Run `composer agent-docs:refresh` to rebuild the snapshot and every generated index.
6. Run `composer agent-docs:check` and review the generated diff before committing.
7. Complete the pull-request capability-impact section. Name affected record IDs and unresolved live-runtime verification.

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

Phase 16 leaves one reviewed implementation candidate: bounded exact-proposal summary inspection. Any future adapter must use side-effect-free summary/readiness readers only and keep raw current/proposed values, downloads, single-entity detail, decision changes, masking, cleanup, recapture, apply, and every other writer outside its accepted arguments and evidence claims. Content Migration readiness is likewise still excluded because its current GET callback can write QA reports; use `cli.content_migration.runs.inspect` only for existing run artifacts.
