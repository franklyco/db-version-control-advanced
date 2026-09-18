# Protocol and storage design

All endpoint names and tables below are proposed. No route or database migration is registered by this package. Draft protocol `agency-control.observation.v0.2` supersedes the previous draft; DBVC plugin version, storage schema version, canonicalization profile and protocol version are independent.

## Authenticated operations

| Proposed route under `dbvc-agency/v1` | Permission and result |
|---|---|
| POST `/enrollments/exchange` | Consume a single-use expiring enrollment invitation; bind a new environment principal |
| POST `/observations` | Report-only principal; validate bounded batch, store observations and scoped receipts |
| GET `/inbox` | Only authenticated environment's authorized deliveries, bounded cursor page |
| POST `/inbox/ack` | Acknowledge only IDs/cursor offered to that principal and durably accepted locally |
| GET `/capabilities` | Authenticated compatible domains/profiles/limits; no blanket execution authority |

The namespace version does not imply schema draft v0.2 is stable. Freeze the supported contract before enabling transport. Reject unknown mandatory fields, unsupported profiles, wrong environment/epoch, oversize payloads, and malformed hashes; do not silently reinterpret them. A future additive version requires explicit compatibility tests.

Prefer one reviewed authentication implementation. For the pilot, evaluate WordPress Application Passwords with a dedicated restricted service user per environment and explicit enrollment binding. Require the expected application-password principal for machine routes; an administrator cookie or arbitrary user ID is not interchangeable enrollment authority. If current HMAC primitives are retained instead, review request/body/route binding, replay state, expiry, rotation and revocation first. Do not ship both methods merely to increase options.

TLS verification is mandatory for hosted transport. Local development requires a trusted local certificate or an explicit lab-only configuration that cannot flow to production. Separate hub configuration authority from ordinary client editors. Limits should be centrally declared and negotiated. Initial test values may be 50 observations/256 KiB per batch, 100 inbox items and 10-second request timeout; these are proposed starting limits, not measurements.

ACK means durable receipt, not target content synchronization. Prefer per-event outcomes: accepted, duplicate, rejected, reconciliation_required. If a whole batch fails before persistence, retry the same immutable events. Same scoped event ID plus different request digest is a conflict. Sequence reuse under the same epoch also conflicts. Authentication occurs before deduplication results are disclosed.

M1 has no hub enrollment yet. Use an explicitly local, provisional environment/epoch for its observation fixtures and outbox; this is not network authority. At M2 enrollment either bind that proposed identity after collision checks, or create a new enrolled epoch and perform a fresh inventory. Never rewrite old immutable event bodies to impersonate the new enrollment. Retain old local history separately and emit new reconciled observations under the accepted identity before transport starts.

## Logical stores and constraints

Create only stores needed by the active milestone. Names below use placeholder `wp_`; actual SQL uses `$wpdb->prefix` and verified engine/collation constraints.

| Owner / suggested store | Key data | Required indexes and invariants |
|---|---|---|
| Connector `dbvc_ce_state` | Environment/epoch, sequence allocator, schema and connection state | One current enrollment; atomic sequence allocation; no secret returned by status reads |
| Connector `dbvc_ce_objects` | Domain/instance identity, generation, snapshot, profile, observation sequence | Unique domain/instance/profile; mapping ambiguity fails closed |
| Connector `dbvc_ce_jobs` | Dirty key, generation, attempts, available_at, lease token/expiry | Unique dirty key; due/lease index; generation-aware completion |
| Connector `dbvc_ce_outbox` | Immutable event/body digest, sequence, attempts and receipt | Unique epoch/event and epoch/sequence; pending/due index |
| Connector `dbvc_ce_inbox` | Hub delivery ID/cursor, source reference, local receipt | Unique hub/enrollment/delivery; persist before ACK |
| Hub `dbvc_ac_environments` | Agency/client binding, epoch, principal binding, enabled/revoked, last contact | Unique environment; enrollment principal constraints; agency/client/status index |
| Hub `dbvc_ac_subscriptions` | Source/target/domain and framework definition mappings | Unique typed subscription identity; both ends within authorized scope |
| Hub `dbvc_ac_events` | Scoped event ID, digest, epoch, sequence, received_at and payload | Unique agency/environment/epoch/event; separate unique sequence key |
| Hub `dbvc_ac_deliveries` | Event/target, monotonically assigned cursor, pending/acked/cancelled | Unique event/target; target/status/cursor index |
| Hub current projections | Per source epoch/object/profile latest snapshot and completeness | Conditional update on newer object-specific sequence |
| M3 baselines / framework records | Pair/object/profile agreement; immutable definition versions and override intent | Baseline advances only on confirmed agreement; framework version never overwritten |

Logical records may share a physical table if constraints and access patterns remain clear. Avoid a serialized option containing the entire queue/registry. Small options can store gates and schema versions, with explicit non-autoload handling where appropriate. Store no arbitrary source payload in public uploads.

Use existing database/job helpers only if they satisfy these semantics. Validate index byte widths on DBVC's supported MySQL/MariaDB versions; do not blindly index multiple 128-character UTF-8 IDs. Bounded ASCII identifiers, surrogate keys and separately indexed digests are options; if using digest keys, compare original scoped values before treating equality as identity.

## Crash and concurrency contract

- Snapshot revision, sequence allocation and outbox insertion must not produce an acknowledged dirty item without a durable event. Rollback/gap recovery is explicit.
- Hub receipt, delivery generation intent and projection update must survive partial failure. Either commit together or persist a durable routing job in the receipt transaction.
- Concurrent retries use database uniqueness, not check-then-insert in PHP.
- Lease claims/renewals/completion use compare-and-set tokens. A late worker is fenced out.
- A partial migration pauses the affected module and presents an actionable state; it does not enable half-created routes.
- Event pruning retains sufficient replay protection through the full retry/credential lifetime. A compact tombstone can outlive raw payload retention. Expired replay windows require re-enrollment/reconciliation, not silent acceptance.

## Rich details and privacy

Initial observations contain identity, profile, hash, existence and completeness, not raw page content, titles, field values or recipients. Even this metadata is scoped. A rich diff requires separately authorized payload upload/retention. An offline source cannot be queried directly; queue a snapshot request for it to pull. If the exact revision is unavailable, show unavailable; never substitute a newer snapshot under an old event.

Canonicalization must define number handling and object-versus-list encoding across PHP and any other implementation. The Python reference hash is illustrative only; it is not the normative cross-language encoding. Build golden bytes/hashes from verified Bricks fixtures before enabling cross-site comparisons.

Official references: [REST authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/), [plugin tables and upgrades](https://developer.wordpress.org/plugins/creating-tables-with-plugins/). These support WordPress primitives, not a claim that this proposed protocol is implemented.
