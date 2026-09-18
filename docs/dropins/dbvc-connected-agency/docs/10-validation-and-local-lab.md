# Validation and disposable local lab

## Lab roles

Use one hub, A-production, A-staging, B-production and B-staging. These labels describe disposable fixtures, not authorization to change actual production websites. Optional A-local can be stopped to demonstrate delayed delivery. Use distinct environment and client identities even if sites originated from the same blueprint.

Record the actual loaded DBVC path for each installation, Git HEAD plus dirty digest, WP/PHP/DB/Bricks/ACF versions, add-on gates, schema versions and scheduler mechanism. A passing test in one checkout is not verification of a different copied plugin inside LocalWP.

## Required case matrix

| Group | Cases | Expected evidence |
|---|---|---|
| Isolation | Both off, connector only, hub only, deliberate both on; Bricks/VE absent | Expected hooks/routes/stores/assets present or absent; no accidental self-enrollment |
| Capture | Bricks create/update/delete, service save and meta changes, CLI edit, exports disabled, VE disabled | Correct persisted snapshots with minimal save-side work |
| Canonicalization | Ordered arrays, unordered maps, missing/null/empty map/empty list, meaningful `time`, ID localization | Correct golden bytes/hash or explicit unsupported profile |
| Collection coverage | Neighbor edit and collection reorder | No dropped meaningful differences; supported/unsupported coverage visible |
| Reconciliation | Lost signal, direct fixture DB edit, partial inventory, deletion | Recovery without inferring deletion from partial data |
| Durability | Worker crash before/after insert, expired lease, generation changes during read | No lost dirty work, no duplicate effective event, stale worker fenced |
| Routing | Framework A class, unsubscribed A class, A service, B control, disabled subscription, offline A staging | Scoped target list, framework review only when subscribed, no B delivery |
| Authority | Forged agency/client/recipients, wrong epoch, revoked principal, forged apply origin | Rejection or normal review; no authority from wire fields |
| Ordering | Event 12/X before 11/Y; 12/X before 11/X; sequence gaps; same ID/different body | Y updated; X not regressed; gap retained; digest conflict |
| Transport | Timeout after durable receipt, retries, throttling, partial batch error, restart | Immutable IDs, dedup, recoverable per-event outcomes |
| Inbox | ACK guessed ID, cross-client cursor, disabled target, persisted-before-ACK | No unauthorized visibility or false acknowledgement |
| Cloning | Copied enrollment, URL change, backup counter rollback | Connection held until new epoch/enrollment; content lineage preserved |
| Baselines | Source-only, target-only, converged, conflict, no baseline, incompatible/partial/stale | Correct classification; notifications do not advance agreement |
| Framework | Behind version, local drift, approved override, changed override | Independent version and drift status |
| Lifecycle | Disable during jobs, re-enable after changes, migration failure, downgrade | No surprise network; reconciliation required; old code held on newer schema |
| Performance | Idle public request, edit burst, bounded fleet simulation | Actual measurements and limits; no fabricated capacity |
| Later prepare/apply | Concurrent external Bricks edit, stale target, lost response, rollback after later edit | Safe block or proven conditional write; verified recovery |

Use actual object UID fixtures, not guessed WordPress numeric IDs. Later transfer tests should intentionally give the two environments different numeric IDs.

## Existing validation tools

Verify current availability before invoking: `composer test:smoke`, `composer test`, focused `vendor/bin/phpunit --filter ...`, `composer agent-docs:query -- <tags>`, `composer agent-docs:refresh`, `composer agent-docs:check`, and `git diff --check`. Use `npm run build` only for affected frontend code. Read fixture/runtime scripts before running them; some mutate data.

Existing test leads include BricksAddonIdempotencyTest, BricksAddonPackagesTest, BricksAddonPhase19A/B/C/DTest, BricksPortabilityManagerTest, BricksCliTest, BricksReferenceMappingTest and CoreImportUidPreservationTest. Select tests relevant to changed owners; do not run unrelated fixture scripts to inflate evidence.

## Package checks versus product checks

The Python tests exercise synthetic contract/routing/order/lease examples and archive integrity. PHP templates have a small standalone contract harness for local PHP execution; their integration with WP is still untested until performed locally. None of these tests proves WordPress storage, HTTP authentication, real Bricks extraction, concurrent SQL claims, rendering, application or recovery.

For each implemented capability record exact input/command, fixture scope, expected and observed result, authoritative stored-state checks, cleanup, active checkout, and untested conditions. Use the repository's existing evidence format; no new parallel runtime ledger is required.

Reporting tests allow observation/queue/identity-management writes but independently compare source content to ensure the reporter does not edit it. Failures caused by unrelated baseline issues are recorded rather than silently weakening assertions.
