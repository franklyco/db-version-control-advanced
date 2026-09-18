# Performance and operations

These are design targets and measurements to collect, not measured DBVC guarantees.

## Work placement

| Context | Allowed work | Avoid |
|---|---|---|
| Public request, modules off | Bounded gate check | Table creation, directory scans, remote calls, new feature assets |
| Relevant editor save | Small dirty-marker update and scheduling hint | Network, snapshot fleet fan-out, broad export, full-site hash |
| Background observation batch | Bounded collection read, object diff and outbox persistence | Re-reading a whole collection once per item |
| Transport worker | Bounded batch, retries, inbox persistence | Infinite retries in one request, synchronized polling bursts |
| Hub dashboard | Paginated indexed stored projections | Live remote requests to each site |
| Reconciliation | Resumable inventory with coverage checkpoint | Unbounded nightly full-site work at the same time across the fleet |

Bricks collection hashes should use one read/parse per batch, then per-item comparisons. If ordering between items matters, capture that separately; faster per-item processing must not silently omit semantics. Avoid loading large historical payloads when a status row needs only a hash and timestamps.

## Scheduling

WP-Cron is triggered by page loads; it is not a constantly running worker. The hosted hub should use a reliable system scheduler invoking reviewed WordPress worker entrypoints. LocalWP requires an explicit scheduled runner while the site is running, or clearly labeled best-effort page-triggered work. A stopped laptop cannot report or pull. [WordPress cron documentation](https://developer.wordpress.org/plugins/cron/)

Use jitter on polling and retries. Retry transient network errors and throttling with bounded exponential backoff; honor bounded `Retry-After`. Authentication/revocation and contract failures need actionable terminal/held states. Keep one durable event ID/body across retries. Sending success without an ACK is uncertain delivery, not a reason to create a new event ID.

Proposed lab defaults: at most 50 jobs per run, 10-second worker wall budget, 50 events per HTTP batch and a 60-second idle poll with jitter. Tune from evidence and hosting constraints before shipping. Idle environments can back off, while an active local outbox can schedule a nearer send. Only one worker lease per relevant lane should own work at once.

## Fleet arithmetic

Request rate is approximately active connected environments / poll interval, before retries and pagination. For illustration, 1,500 environments polling every 60 seconds yield about 25 requests/second at the hub even when no content changes. This is arithmetic, not a tested capacity claim. Dashboard queries, WP bootstrap cost, credentials lookup and event fan-out add load. Measure before promising hundreds of clients on a small shared host.

Hub separation into its own host can happen while the code remains in the same DBVC repository. A separate repository does not lower request volume. If measured worker/database pressure exceeds the pilot design, first address scheduling/indexing/payloads; then consider dedicated workers or service extraction based on evidence.

## Observability

Record queue depth and oldest pending age, worker success/failure and duration, retries, last authenticated contact, last successful inventory, source sequence gaps, delivery cursor lag, unsupported domains, profile mismatches and held enrollment states. Logs use correlation IDs and redacted structured fields. Retain compact metrics and receipts without indefinite raw content history.

Every UI summary should distinguish no changes found, no data received, incomplete inventory, offline and unsupported. A green badge cannot be derived solely from an empty queue.

## Evidence to collect

Compare modules-off baseline versus connector-on idle versus representative saves and bulk operations. Measure p50/p95 save duration, DB query count, peak memory, queue processing rate and end-to-end report latency. Run one controlled burst plus restart/retry experiment. Set numerical acceptance budgets from the actual site/hub baseline; do not fabricate a universal sub-millisecond target.

## Rollout behavior

Use one disposable hub, then one real studio pilot ecosystem only after local reporting/isolation gates. Release connector changes with protocol compatibility for older hubs and vice versa. Pause unsupported operations with a clear reason. A shared DBVC release cycle is accepted initially; its unrelated regression and update-distribution cost is a reason for eventual independent packaging if it becomes material.
