# M0: current-state discovery and first observation contract

Status: **read-only discovery plan.** M0 creates an evidence/reuse map; it does not create an add-on, enroll a site, modify a database, start a worker, or use production credentials.

## Objective

Determine whether the proposed first reporting-only slice can be built safely on the actual DBVC checkout:

- one individual Bricks global class;
- one individual Bricks variable;
- one published `service` post with a known existing identity.

The deliverable is a compact current-state report plus a completed reconciliation record for each selected domain. It is not an implementation claim.

## Required inputs

1. Current branch, HEAD, dirty-file summary, and active checkout path.
2. Active LocalWP site identity plus the actual loaded DBVC plugin path, WordPress/PHP, Bricks, and ACF versions if runtime inspection is authorized.
3. The preserved [source manifest](source/SOURCE-MANIFEST.md), this guide, and [reconciliation map](reconciliation.md).
4. The smallest relevant Bricks plan/tracker, core identity reference, and proposal/import service records.

Do not assume the source archive's historical commit, test results, storage shapes, or runtime behavior are current.

## Read-only work sequence

1. Record Git provenance and the unrelated dirty boundary. Never reset, restore, clean, stash, stage, or overwrite it.
2. Inspect DBVC's add-on registration convention, service namespaces, migrations, logger, scheduler/worker facilities, and documentation-maintenance ownership.
3. Inspect actual Bricks storage for one class and variable fixture. Determine individual identity candidates, option boundaries, ordering semantics, and how unrelated entries are preserved.
4. Inspect one `service` fixture's portable identity, post/meta lifecycle, supported fields, and safe read boundary.
5. Trace candidate lifecycle signals and their gates. Separate WordPress hook availability, DBVC hook behavior, Visual Editor journal writes, and persisted-state rereads.
6. Inspect existing connection/queue/package primitives for identity, authorization, replay, retry, receipt, and clone behavior. Do not infer a generic executor from a specialized command.
7. Complete the reconciliation record and choose the smallest M1 slice or explicitly block it.

The archived `scripts/inspect_dbvc.py` may inform an inventory only after its source is reviewed. If it is run, extract it to a temporary directory outside the repository and retain its output outside version control until it has been reviewed for local paths or sensitive metadata.

## M1 contract to freeze after discovery

M1 remains local and default-off. It needs, at minimum:

| Area | Contract |
|---|---|
| Object identity | Explicit domain + existing portable identity or explicit `identity_missing`; a read never backfills UIDs |
| Snapshot | Versioned, domain-specific canonical projection with semantic hash, completeness, and preservation of meaningful ordering |
| Capture | Allowlisted hints mark a local dirty object; a bounded worker rereads settled persisted state before an observation is emitted |
| Reconciliation | A bounded scan repairs missed hooks/outbox gaps and marks incomplete coverage rather than claiming clean state |
| Outbox | Durable event identity, sequence, retry state, and duplicate protection; no remote send in ordinary editor saves |
| Safety | No content writes, no credentials, no enrollment, no network request, and no auto-promotion/apply |

## M1 acceptance cases

- A class/variable/service edit produces one settled local observation rather than a notification for every low-level write.
- Reordering a meaningful list remains detectable; map-key reordering does not create noise.
- Missing, null, empty string, empty list, empty map, and deleted state remain distinct.
- An export-disabled or skipped DBVC hook does not cause a false-clean state.
- A lost capture hint is repaired by reconciliation.
- The reporter itself makes zero client-content changes.

## Stop conditions

Stop and return a repair decision instead of adding code if any of these is true:

- The installed Bricks shape cannot isolate a selected object without overwriting unrelated values.
- Identity or clone semantics cannot be verified for the selected domain.
- Existing connection primitives cannot be wrapped without changing legacy behavior.
- A reliable persisted-state reread or durable local storage boundary is unavailable.
- Required runtime provenance cannot be established.

## Agent handoff prompt

```text
Perform M0 for Agency Control / DBVC Connected Environments.

Read AGENTS.md, docs/README.md, docs/agent-entrypoints.md,
docs/implementation/proposed/agency-control-connected-environments/m0-discovery.md,
and reconciliation.md before wider discovery. Preserve the current working tree.

Use the current DBVC checkout and only authorized LocalWP/runtime sources. Record
branch/HEAD/dirty boundary, active loaded plugin/runtime provenance, exact relevant
services/hooks/storage shapes, and the reuse/adapt/build/defer decision for one
Bricks class, one Bricks variable, and one service post.

Do not create or register an add-on, write code, edit content, create credentials,
send network traffic, run a writer, stage, commit, reset, clean, or switch branches.
Return the smallest safe M1 observation-only slice, its test matrix, blockers, and
the exact authority required for implementation.
```
