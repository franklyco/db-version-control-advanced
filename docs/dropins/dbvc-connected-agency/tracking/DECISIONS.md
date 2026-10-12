# Decisions

| ID | Decision | State |
|---|---|---|
| ADR-001 | Keep code in existing DBVC repository and distribution initially | Accepted 2026-09-16 |
| ADR-002 | Connector and hub are independently gated add-ons | Accepted |
| ADR-003 | DBVC owns execution; hub owns coordination; VE stays optional | Accepted |
| ADR-004 | Hub-mediated outbound HTTPS; no simultaneous peer mode | Accepted |
| ADR-005 | First pilot observes/reports; apply comes after baseline/preparation gates | Accepted |
| ADR-006 | Dedicated queue/receipt storage with atomic uniqueness/leases | Design requirement; actual schema pending local evidence |
| ADR-007 | Preserve lineage but rotate environment enrollment on clone/restore | Accepted |
| ADR-008 | No new frontend framework or external queue for M1/M2 | Accepted |
| ADR-009 | WP Application Password per enrolled environment (capability-less service user, UUID bound to the enrollment; Bricks HMAC not reused) | Accepted 2026-09-18; implemented in M2 step 1 |
| ADR-010 | Single-site WordPress pilot; multisite unsupported until designed | Proposed MVP boundary |
| ADR-011 | Coalesced drift reporting is not a complete audit log | Accepted |
| ADR-012 | Sender origin never suppresses review without trusted receipt correlation | Accepted |
| ADR-013 | Save-side capture listens to WordPress core option hooks, not `dbvc_after_option_update` (export-gated) | Accepted 2026-09-17; verified in includes/hooks.php |
| ADR-014 | Separate version-1 canonicalizer for connected profiles; the Bricks portability normalizer is not reused for hashes (strips `time`, sorts lists) | Accepted 2026-09-17 |
| ADR-015 | Connector stores are dbDelta InnoDB tables created only through the enable lifecycle; gate options are not created on ordinary requests | Accepted 2026-09-17; lab-verified |
| ADR-016 | Collection order is a separate projection (`instance_uid=collection.order`, profile `bricks-collection-order-v1`) inside each domain | Accepted 2026-09-17 |
| ADR-017 | Enabling from disabled requests a full reconciliation (`origin=reconciliation`); the worker propagates the signal origin into events | Accepted 2026-09-17 |
| ADR-018 | Operational keys are excluded on options import via the new core `dbvc_import_options_data` filter as well as on export | Accepted 2026-09-17 |
| ADR-019 | The hub assigns the installation epoch at exchange; the connector restarts its sequence at 1 per epoch and supersedes (never rewrites) older-epoch outbox rows | Accepted 2026-09-18 |
| ADR-020 | Hub credential sealed with a salt-derived key (libsodium/OpenSSL); undecryptable → explicit hold, never a silent send | Accepted 2026-09-18 |
| ADR-021 | Connector calls the hub via `index.php?rest_route=` so hubs without pretty permalinks work and POSTs never follow redirects | Accepted 2026-09-18; lab-verified |
| ADR-022 | Routing runs synchronously after durable receipt (deliveries exist before the ACK returns); `routing_state=pending` remains the durable job for retries | Accepted 2026-09-19 |
| ADR-023 | Held targets keep accumulating deliveries (retrieval is refused until release); only revocation removes a target from routing | Accepted 2026-09-19 |
| ADR-024 | `wp.service` identity is DBVC's `vf_object_uid` (service type must be a DBVC post type); masked fields mark projections incomplete rather than hashing placeholders as content | Accepted 2026-09-19 |
| ADR-025 | Hub comparison is hash-level over each environment's own reports; pairing is shared lineage (equal instance UID) or an explicit operator link — the hub never receives names, so no name-based auto-pairing | Accepted 2026-09-19 |
| ADR-026 | Baselines advance only via explicit `baseline-confirm` (equal, complete, fresh) or `--accept-absent` for one-sided verified absence; an unpaired object without a baseline is `baseline_required`, never `outgoing` by assumption | Accepted 2026-09-19 |
| ADR-027 | Bricks add-on drift/protected-variant/package machinery informs M3 but is not reused: whole-option artifacts, two-way compare, hash-less overrides, `strcmp` versions | Accepted 2026-09-19; review recorded in the implementation guide |
| ADR-028 | Framework definition versions are immutable rows whose distance comes only from the studio-supplied `version_order` (unique per channel); labels are opaque and never compared; a version belongs to one channel and is re-published (`--from-version`) to reach another | Accepted 2026-09-19; lab-verified (`1.10` after `1.9`) |
| ADR-029 | An override is an exact approved hash with the frozen policy revision, rationale and approver; a new desired version flags it `needs_rebase_review` and never rewrites or removes it; reverting to the framework hash stays `override_changed` until the studio detaches | Accepted 2026-09-19 |
| ADR-030 | Framework status is a read-only report over projections, definitions and overrides; it never adopts, promotes or applies — adoption (`adopt-version`), publication and approval are separate explicit studio actions | Accepted 2026-09-19 |
| ADR-031 | `wp.service` coverage is a `collection.order` projection under `wp-service-inventory-v1` (sorted portable UID set), complete only when every observed post has a UID; emitted on reconciliation and after each per-object change, so the hub verifies absence for services the same way as for Bricks members | Accepted 2026-09-19; lab-verified |
| ADR-032 | Review items are resolved through the framework status report, never a second classifier: `review-classify` stamps the current drift/version and auto-resolves superseded, clean/current and approved-and-current items; anything else needs an operator note | Accepted 2026-09-19 |
| ADR-033 | Administrator panels are read-only tables plus one action (invitation): admin-post handler registered only inside wp-admin, token shown once via a per-user transient, form element outside DBVC's settings form via the HTML `form` attribute; `admin-page.php` carries only guarded one-line calls | Accepted 2026-09-19; browser-verified in the lab |
| ADR-034 | A release manifest fixes selected objects and after-hashes from the source's current projections; payloads are the source connector's recorded canonical bodies, supplied on the connector's own outbound poll and verified by hash on the hub before the release seals — a changed source records a mismatch, never a substituted body | Accepted 2026-09-19; lab-verified |
| ADR-035 | Prepare is a target-side dry run over current persisted state: identity by portable UID only (plus operator lineage links), whole-object patch with container fingerprint or explicit blocker, receipt stored locally before it is reported, expiring, never permission to write; the hub annotates receipts with its own projections/baselines but adds no authority | Accepted 2026-09-19; lab-verified |
| ADR-036 | Creation of an object the target does not know is a separate decision: an unmatched UID blocks (`creation_requires_decision`) rather than falling back to name, slug or position | Accepted 2026-09-19 |
| ADR-037 | Dependencies are discovered on the target from the after-state and reported as a ledger (present/selected/unresolved/unsupported, all required); unresolved or unsupported entries block the item, media transfer is `unsupported` in prepare, and no dependency is created or remapped by discovery | Accepted 2026-09-19 |
| ADR-038 | A deletion is released only from a verified absence on the source (tombstone), travels as the canonical `null` payload, and prepares to `remove_object` (present) or `noop` (absent or never known) — never to a creation decision | Accepted 2026-09-19 |
| ADR-039 | Apply is a separate connector gate, off by default, never implied by observation, cleared when the connector is disabled and excluded from options transfer; capabilities advertise it only while on | Accepted 2026-09-19; lab-verified |
| ADR-040 | The conditional-write strategy for shared Bricks option containers is a single SQL statement guarded by the receipt's raw storage fingerprint (`WHERE SHA2(option_value,256) = …`) after a fresh raw re-read and a journalled before image; affected rows ≠ 1 is `stale`, never a retry-with-overwrite, and a failed verification is compensated by the same conditional write back and verified | Accepted 2026-09-19; lab-verified (concurrent edit survived) |
| ADR-041 | An approval binds release digest, receipt digest, target epoch and policy revision, expires with the receipt, requires the receipt's before-state to agree with the hub's last projection of the target, and is consumed by exactly one execution receipt; only items reported applied *and* verified advance the pair baseline; a lost response is resolved by re-sending the journalled receipt, never by executing again | Accepted 2026-09-19 |
| ADR-042 | Generated Bricks CSS/cache is not rebuilt by apply in this step; every applied receipt carries the warning, and rendering evidence stays a separate gate | Accepted 2026-09-19 |

Record changes with rationale, source evidence and affected contracts. Do not silently promote proposed decisions into implemented capability claims.
