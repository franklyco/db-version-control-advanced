# Identity, baselines and drift

## Separate identity concepts

| Identity | Meaning |
|---|---|
| agency_id | Isolation boundary for hub ownership, even if MVP supports one agency |
| client_id | One customer's ecosystem; assigned/verified by the hub |
| environment_id | One enrolled installation; never inferred only from URL |
| installation_epoch | Changes on explicit re-enrollment/clone restoration; distinguishes sequence lifetimes |
| entity_uid | Existing DBVC `vf_object_uid` for posts/terms within a client lineage |
| instance_uid | An individual Bricks class, variable or other domain object inside a client lineage |
| definition_uid | A studio-owned framework definition, separate from all client copies |
| subscription_id | Explicit link from a client/environment instance to a definition and version policy |

Framework definition identity is not the same as the client content UID. Different clients may share a definition but must not have globally interchangeable content identities. Key lookups with agency, client, environment and domain as applicable. Object name and numeric WordPress ID are attributes, not authorization or cross-site identity.

For Bricks collection entries, persist mappings in DBVC-owned sidecar storage. Do not assume adding arbitrary metadata to a Bricks option is supported. On a clone of the same client, preserve instance lineage; on a new client derived from a blueprint, enroll a new client lineage and explicitly map subscriptions. Do not rotate UIDs across the DB indiscriminately. A collision or ambiguous rename produces a review task.

## Two independent comparisons

1. **Environment synchronization:** compare staging and production against their last mutually confirmed state for that object and comparison profile.
2. **Framework drift:** compare an instance with the framework version it adopted, plus an approved override where present. Separately show whether a newer framework version is available.

A new framework release makes a site behind its desired version, not necessarily locally drifted. An approved override is expected variation, but a later change to that override can create fresh drift. A temporary override is not silently promoted to the studio standard.

The starter comparison returns `version_differs` when versions are merely unequal. Only a trusted framework release/channel ordering can refine that to behind, ahead or a channel mismatch; string inequality alone does not prove that a site is behind.

## Environment three-way states

| Baseline / source / target | State |
|---|---|
| All equal | synchronized |
| Source differs; target equals baseline | outgoing change |
| Target differs; source equals baseline | incoming change |
| Source and target equal each other but differ from baseline | converged; reconcile baseline after confirmation |
| Both differ from baseline and each other | conflict |
| Baseline unavailable or profile incompatible | baseline required / comparison unavailable |
| Inventory incomplete, stale beyond policy, identity missing | unknown; never clean |

Keep baseline per object, pair and profile; an environment-wide timestamp is insufficient. Partial releases advance only successfully verified objects. Seeing or acknowledging an event does not advance a baseline. If both ends are equal, re-read/version-check both before recording agreement. With three environments, maintain explicit pair comparisons or a well-defined client checkpoint with per-environment acknowledgements; do not claim transitive synchronization from one successful pair.

## Hash contract

Use a versioned, domain-specific canonical projection. Preserve array ordering where meaningful (Bricks element trees, CSS declarations where order matters, galleries, repeater rows). Sort object/map keys only when semantically unordered. Strip only documented volatile paths; a user field called `time` may be meaningful. Distinguish missing, deleted, present-null, empty string, empty list and empty map.

Define number/string/null handling explicitly and include canonicalizer version in every hash comparison. Do not silently compare different canonicalizer versions. Maintain (a) portable semantic hashes for cross-environment comparison and (b) destination storage fingerprints for stale-write protection. ID/URL localization can make raw payload hashes differ without semantic drift.

Redacted display projections are not authoritative apply hashes. Masked/unavailable fields cannot become ordinary null values or imply clean state. Identity enrollment/backfill is an explicit writer; inventory alone must return `identity_missing` rather than assigning UIDs during a read.

## Overrides

Store definition version, instance, affected paths (initially whole object), exact approved value hash, policy revision, rationale, approver and timestamps. A new framework release can mark the override `needs_rebase_review`; it must not overwrite it. A policy decision can detach, retain, restore or propose promotion. Promotion creates a reviewed new definition version.

## Clones and restore

Environment identity and secrets copied in a database backup are a real enrollment risk. Require explicit clone/restore enrollment; rotate environment credentials and epoch while preserving intended client content lineage. URL changes trigger a connection hold/identity review, not automatic reassignment. Concurrent use of the same identity must be detected, but automatic clone detection is a heuristic and cannot be the only protection. Restored event counters must not be accepted under an old epoch without reconciliation.
