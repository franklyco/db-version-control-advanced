# Reviewed release and recovery design

Build after the reporting and baseline milestones. A release selects individual objects and includes only verified required dependencies. Do not treat a full source domain option as the intended patch for one managed class.

## Target preparation

1. Validate source/target identities, protocol and adapter versions, domains and explicit operation permissions.
2. Resolve object identities with DBVC UID rules. Unmatched UID does not silently fall back to numeric ID/slug. Creation needs a separate supported decision; ambiguous mappings block.
3. Read current target state and compute the semantic comparison plus raw storage fingerprints for all containers touched.
4. Resolve known dependencies and produce a ledger: required/present/selected/remapped/unsupported/unresolved. Incomplete discovery is visible and can block the selected operation.
5. Produce an exact patch: object IDs, paths, expected before hashes, after hashes and assets. Preserve unrelated collection entries and environment-specific fields.
6. Return a prepare receipt and expiry. This is not permission to write.

Reuse the richer Bricks portability session pipeline when its boundaries match. Do not expose old ID-based entity apply or call UI REST handlers internally to skip service rules. Generic service CPT content should adapt the core identity/proposal path; do not shoehorn all content into Bricks artifacts.

## Approved execution

Bind approval to a release digest, target environment, target storage state, dependency ledger, policy revision and comparison-profile version. Re-read under an appropriate write strategy immediately before apply. If anything meaningful changed, return stale and prepare again. No force-reapply/hash-bypass default.

Bricks classes/variables may share one option row. A per-object patch still writes a shared container. A lock respected only by DBVC workers cannot prevent an unrelated Bricks editor save. Prove a conditional-write strategy or establish a bounded editing window covering affected writers. Do not claim concurrency safety from a preflight read followed by an unconditional `update_option()`.

Where a safe supported atomic write boundary is unavailable, keep that operation manual/blocked or require the documented isolated window. Review object-cache and WordPress hook behavior for any conditional storage implementation before choosing it.

Capture a verified before image plus affected files/objects before mutation. Prepare media, map IDs, then write supported domains in dependency order. Target scope is one environment; a fleet rollout is a series of per-target operations, not one distributed atomic transaction. Canary success opens the next cohort; failures pause later cohorts.

## Verification and receipts

Verify canonical object state, destination references, created/reused assets and actual persisted maps. For templates/styles, include a Bricks builder/frontend rendering check and supported CSS/cache regeneration after apply and restore. Generated CSS/font/cache state is not the authoritative framework content and should be rebuilt rather than distributed as a canonical payload unless the adapter explicitly requires it.

A matching hash proves selected data, not correct page rendering or absence of unrelated visual effects. Record these separately. Include operation_id, target identity/epoch, before/after hashes, mapping ledger, backup reference, created objects/files, tests and warnings in the receipt.

## Recovery

Do not promise database-style atomicity across WordPress APIs, hooks, media and files. Use a recoverable operation journal with bounded compensation. Record every completed write and failure state. Verify compensation; never report rollback applied if the restore result was not checked.

Rollback compares current state with the release's verified after-state. Later edits create a restore conflict, rather than being overwritten. Newly created media is removed only if still owned by that operation and unreferenced; reused media is not deleted. Restore related options and regenerate derived assets. Database/uploads backup procedures remain available when fine-grained recovery cannot complete.

Separate lost HTTP responses from lost work: after a timeout ask for operation status, never immediately issue a fresh operation. An in-progress durable receipt needs recovery logic, not a second execution. Partial success does not advance baselines for failed objects.

## Adoption limits

The reviewed tracker still has Phase 25 idempotency/mixed-rollback hardening and Phase 26 LocalWP release gates open. Close only the selected domain boundary first. Do not wait for every historical backlog item, and do not treat the entire tracker as complete because one round trip succeeds.
