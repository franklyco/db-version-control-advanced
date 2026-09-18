# Capture, reconciliation and routing

## Save path

Treat WordPress/Bricks hooks as invalidation hints, not complete authoritative changes. On a relevant save, record a small dirty marker keyed by domain/object or collection and increment its generation. No network, fleet comparison, full-site export, or full history scan belongs in that request.

Where storage is a single Bricks option collection, enqueue the collection once. The worker reads that container once per batch, compares mapped logical objects, and emits only changed objects. Preserve meaningful collection-level order in a separately identified domain if the adapter proves it affects behavior; per-item hashes alone cannot detect all inter-item ordering changes. Mark that coverage unsupported until implemented.

## Worker algorithm

1. Atomically claim a due job with a lease token, expiry, and captured dirty generation.
2. Read persisted authoritative state through the adapter; distinguish unsupported, unavailable, incomplete, missing identity, and proven deletion.
3. Compute a domain/profile-specific projection. Do not generically strip timestamps or sort every array.
4. Check whether the object/container changed while being read. If stable reads cannot be established, retain the dirty marker and retry rather than publish a supposedly complete snapshot.
5. Allocate an environment-epoch sequence and persist observation/snapshot/outbox together. The event ID and body stay immutable on retries. A no-op semantic change need not emit a new event.
6. Acknowledge the dirty marker only if its generation still matches. A newer generation remains pending even when the previous snapshot was successfully persisted.
7. Acknowledge worker completion only with the same unexpired lease token. Expired workers cannot overwrite a successor's progress.

Generation increments are not inferred from millisecond timestamps. Bound job count, memory, retries and wall time. The production store must prove atomic claim/ack semantics; the package's in-memory examples do not.

## Reconciliation

Use bounded resumable inventory with generation/checkpoint metadata to recover lost hooks, manual database edits, failed queue writes and missed deletions. A partial or failed inventory cannot prove absence. Emit tombstones only after an authoritative, complete relevant inventory or a verified post-deletion read. Inventory completion and current-state freshness are separate from event-delivery completeness.

A receiver records sequence gaps and requests reconciliation. An environment-wide maximum sequence is not enough to order different objects: if event 12 for class X arrives before event 11 for class Y, event 11 may still be Y's newest observation. Maintain per-object/profile sequence state and a separate received-sequence gap tracker. Older events for the same object cannot overwrite its newer projection.

Do not advance a delivery cursor past an item that was not durably stored. Server-assigned inbox cursors and source event sequences are distinct. Retention-expired cursors return an explicit resync-required outcome.

## Routing rules

The hub authenticates enrollment and reads agency/client/environment/epoch membership from its registry. The wire observation deliberately omits agency, client and recipients. Sender-supplied `origin` and `causation_id` are informational until matched to trusted operation receipts; a client cannot suppress framework review by claiming `origin=apply`.

| Scenario | Same-client inbox | Studio framework review | Other client inbox |
|---|---|---|---|
| Subscribed framework class edited on A prod | Enabled explicit A targets | Yes | No |
| Unsubscribed Bricks class edited on A prod | Enabled explicit A targets | No | No |
| Service CPT edited on A prod | Enabled explicit A targets for that domain | No | No |
| Verified result of an approved framework operation | Scoped result/observation | Operation outcome, not a new promotion proposal | No implicit delivery |
| Sender merely labels an edit `apply` | Normal authorized routing | Review if subscribed | No |
| A staging temporarily offline | Pending delivery remains | As above | No |
| A staging subscription disabled/revoked | Suppress/cancel delivery per recorded policy | As above | No |

Framework subscription keys bind agency, client, environment, domain, instance UID and definition UID. A shared class name is insufficient. Hub membership changes do not let an environment acquire another client's historical delivery stream. Freeze the routing-policy revision and target set when accepting a report, then recheck authorization at retrieval. Newly added subscribers bootstrap from a current inventory; historical backfill is explicit.

Initial review may be a raw observation pending baseline setup. Do not label it confirmed drift until the corresponding adopted-version comparison exists. Promotion creates a reviewed definition version later; reporting never updates the framework standard automatically.

## Feedback loops

Capture actual state after DBVC/manual/remote writes. Correlate with trusted local operation receipts and verify the resulting hash. Expected apply results can close an operation; they still update observations. If actual state differs from the expected result, record divergence even when an apply marker is present. Do not drop all events from imports or suppress an entire save request merely because one operation ran in it.

## Audit boundary

Coalescing reports settled state and current drift. It is not a complete history of every intermediate keystroke or saved revision. If complete audit history becomes a requirement, add durable mutation-journal capture with explicit scope and retention. Do not represent this pilot as a forensic audit log.
