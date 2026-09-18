# Media Manager Scan and Finding Contract

## Design goal

Provide a complete, navigable view of missing image assignments without running an unbounded synchronous scan or hydrating every field descriptor.

The exact transport and storage must follow current DBVC conventions. This document defines required behavior, not endpoint names.

## Implemented R1-B/R1-C contract

The current working tree implements the internal server contract as follows:

- `ScanCandidateProvider` stores a deterministic list of dynamically eligible post-type/taxonomy sources in the snapshot, processes at most 20 candidates by default (hard ceiling 50), and consumes at most four source queries per call by default, including empty sources.
- `MediaScanService` rechecks policy/catalog eligibility, reads native featured image plus supported top-level/group-only ACF image/gallery values, caches each root ACF read per owner, excludes malformed nonempty values from missing findings, and creates no descriptor.
- Opaque `vemg_*` group and `vemf_*` finding references and `vemv_*` empty fingerprints are deterministic HMACs within one secret-backed scan generation; browser-visible contracts must never expose their server identity inputs.
- `ScanSnapshotStore` uses a separate `dbvc_visual_editor_media_scan_*` transient namespace, compressed group records, explicit user/blog checks, a one-hour default TTL, a five-MiB safe payload ceiling, a latest-generation pointer, and a 30-second atomic option lock released after each update.
- `MediaScanCoordinator` implements scanning/complete/failed/canceled/invalidated states, monotonic expected revisions, stale-call no-ops, replacement, retry at the same cursor, configuration fingerprints, summary recomputation, and per-chunk duration/query/memory/storage metrics.
- No ordinary frontend hook instantiates the scanner. R1-C registers seven protected active-mode REST routes under `/dbvc/v1/visual-editor/media-manager`: start (`POST /scans`), latest (`GET /scans/latest`), explicit list (`GET /scans/{scan_ref}`), lifecycle next/retry/cancel (`POST /scans/{scan_ref}/...`), and one-group expansion (`GET /scans/{scan_ref}/groups/{group_ref}`). No descriptor session, UI asset, Media Library action, or mutation path is added.
- `MediaScanReadModel` projects only safe lifecycle/list/row data. Explicit list/lifecycle/group calls require the current opaque generation and revision. Latest is the deliberate current-user/current-site resume exception.
- List queries allow a 100-character search, allowlisted entity/field/sort values, an opaque group-reference cursor, and 1–50 rows. The model rechecks each returned entity's current eligibility and object capability, performs at most one eligible-row lookahead for `hasMore`, and intentionally returns no exact filtered total.
- Expansion resolves only a stored server-owned group, repeats current owner checks, rescans that one candidate, and compares current HMAC empty fingerprints. It reports safe `missing`, `changed`, `resolved_or_changed`, or `unavailable` field states without returning any owner/field target, fingerprint, descriptor, or write action.

The initial 20-entity/60-finding PHPUnit measurement was 4.661 ms, 24 queries, zero additional allocated/peak memory pages at PHP's reported granularity, and a 4,983-byte compressed snapshot. R1-E later added isolated 100/500/2,000-group compressed snapshot/read/payload measurements; the combined 2,000-group case took 25.425 ms with zero additional queries, 6,291,456 allocated-memory bytes, a 120,475-byte snapshot, and a 24,833-byte 50-row response. The scale fixture intentionally shares one eligible owner and therefore does not replace complete multi-owner candidate traversal, raw ACF read, authenticated REST, or browser performance gates.

## Scan lifecycle

Recommended states:

```text
not_started
    ↓
starting
    ↓
scanning (processed / estimated candidates)
    ↓
complete
```

Additional terminal/interruption states:

- canceled;
- expired;
- invalidated by configuration change;
- failed with retryable error;
- failed with non-retryable configuration error.

## User-triggered behavior

- Do not scan automatically on every Visual Editor page load.
- Opening Media Manager may display the latest valid user/site snapshot.
- `Refresh Scan` starts a new scan or replaces the current snapshot according to current session conventions.
- Only one active scan per user/site should be necessary unless current infrastructure already supports more.
- Leaving the panel may pause client requests; no orphaned background daemon should continue unless an existing safe job framework is deliberately reused.

## Bounded processing

Preferred order:

1. Reuse an existing Media Health or scanning engine.
2. Reuse an existing request-batched DBVC job/session system.
3. Implement a small request-driven chunk loop.

A request-driven scan should:

- enumerate a bounded candidate page/cursor;
- scan only that batch;
- return progress and the next cursor;
- merge compact findings into an expiring user-bound snapshot;
- release request resources promptly;
- tolerate refresh, cancellation, duplicate next-batch requests, and stale client responses.

Chunk size must be measured, not hardcoded from this guide.

## Snapshot storage

A scan snapshot should be:

- user-bound;
- site/environment-bound;
- short-lived;
- versioned by scanner/schema/config fingerprint;
- compact;
- non-authoritative for writes;
- invalidated when field eligibility configuration materially changes.

Prefer existing transient/session storage. Do not add a custom table in R1 without measured necessity.

## Finding-group identity

One result-table row represents one entity and contains one or more field findings.

Use an opaque server reference for the group. The reference must not expose enough information to target arbitrary owners or fields.

A group record can conceptually include:

```text
opaque group reference
safe entity label
type/taxonomy label
permitted frontend route or null
missing finding count
counts by field family
safe modified/scanned timestamp
scan status
available non-mutating actions
```

Do not include full descriptors in the list response.

## Field finding identity

A compact nested finding in the snapshot can conceptually include:

```text
opaque finding reference
safe field label
field family
safe parent/group context label
scan-time empty-value fingerprint
status
```

R1-C exchanges the opaque group reference only for fresh safe row statuses. A later R2 field-open action may exchange a still-missing finding for a fresh descriptor after a new authorization gate and another full revalidation; row expansion itself creates no descriptor.

## Scan-time fingerprint

Store the smallest safe evidence required to detect that the scan observation is no longer current. Depending on the existing descriptor model, this may be:

- canonical value hash;
- owner modified/version evidence plus canonical value hash;
- descriptor stale token;
- parent-field hash for nested ACF values.

Do not store or expose sensitive raw field values merely to support comparison.

## Result listing

The result endpoint/read model must support:

- search;
- entity-family filter;
- field-family filter;
- deterministic sort;
- pagination/cursor;
- scan state and summary;
- a stable response version so stale requests can be ignored.

Search is server-side for large result sets. Sanitize and bound query length.

Implemented R1-C cursor semantics use the last returned opaque group reference within the current filtered/sorted projection. A cursor must still exist in that projection or the request fails closed. Exact filtered totals are omitted so the server does not need to capability-check every matching entity on every page.

## Summary counts

At scan completion, provide:

- candidate entities processed;
- entities with findings;
- total findings;
- featured-image findings;
- ACF image findings;
- ACF gallery findings;
- skipped/unsupported count where useful;
- scan duration or completion timestamp if current UI conventions allow.

Counts are snapshot observations, not guarantees that every item remains empty.

## Row expansion

When a group expands:

1. Validate the opaque reference and snapshot ownership.
2. Recheck entity existence, status, and capability.
3. Re-evaluate currently applicable supported fields.
4. Compare current values with scan evidence.
5. Remove or mark findings already resolved.
6. Return safe field labels/context and current statuses only; do not hydrate descriptors in R1.
7. Reserve any fresh descriptor/editor metadata bridge for the separately gated R2 contract.

Do not trust the field list originally held by the browser.

## Progress UI

The client may show:

- determinate progress when candidate total is known cheaply;
- processed counts and indeterminate progress when it is not;
- partial results if the architecture supports stable progressive listing;
- retry for a failed chunk;
- cancel/close without corrupting the snapshot.

Do not claim scan completion while requests remain outstanding.

## Error handling

Classify errors where possible:

- transient request/network failure;
- expired/invalid scan reference;
- provider unavailable;
- ACF unavailable;
- permission changed;
- scanner configuration invalid;
- resource/limit failure;
- unexpected server error.

Display a useful user message and log technical context using current DBVC logging without leaking field values or tokens.

## Performance requirements

- No full descriptors in collapsed rows.
- No attachment metadata or thumbnails fetched until a row expands or a selection is made.
- Cache stable field definitions/location mappings within a scan where safe.
- Batch object metadata and capability/permalink work using current WordPress cache behavior.
- Bound response size and row count.
- Large scans must not exhaust PHP execution time or memory in one request.
- The table must remain interactive while scan status updates.

## Scan acceptance criteria

- A scan can be started, refreshed, completed, expired, and retried safely.
- A duplicate/stale next-batch request does not corrupt results.
- Scan state is isolated by user/site.
- Unauthorized entities do not appear.
- Results are complete through pagination/virtualization, not necessarily one payload.
- Expanded rows recheck current state.
- A scan snapshot cannot be transformed into arbitrary mutation authority.
- Ordinary frontend page loads incur no full-site scan cost.
