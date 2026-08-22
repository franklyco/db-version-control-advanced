# Proposal — Persistent Media Index & Background Refresh

**Status:** direction chosen 2026-08-18 — **Phase 1 first** (persist the last completed scan for instant re-open + lazy per-entity dirty re-scan on media-field change; defer the full background-cron reconciliation to Phase 2). To be scoped as a discovery pass **after R2-E4** ships; not yet started. The full write-up below is retained for the discovery pass.

## The idea (as requested)

1. Move Media Manager scan/cache results from ephemeral transients to durable storage — a **custom DB table** or **local JSON** under the DBVC `wp-content/` sync folder (backup-friendly).
2. **Scan once, in chunks, on first use** on a site; persist the result.
3. **Keep it fresh with background processes** — update the stored result for individual modified entities whenever a media field or Media Library item changes, and periodically reconcile via cron.

## Why it is worth doing

- **UX:** the Manager would open with instant, always-current results instead of "run a scan and wait." This is the natural maturation of the feature.
- **Reach:** enables site-wide media-completeness reporting, dashboards, and cross-entity views that an ephemeral per-session snapshot cannot support well.

## What it costs / the real risks

1. **Security-model shift (the big one).** The current opaque-reference security (HMAC of a value under a per-scan `generation`) and eligibility are baked into an **ephemeral, user/blog-bound** snapshot at scan time. A **shared, persistent** index cannot bake per-user capabilities into stored rows: the read model must **re-filter by the current user's capabilities at read time**, and refs need a stable generation tied to the index version rather than a per-scan one. Mutation already re-checks capability/eligibility at write time (good), but the read/list path would need a redesign. This is not a bolt-on.
2. **Invalidation governance (the hardest part).** A persistent index is only as good as its invalidation. It must update on: `save_post`/status transitions; `added/updated/deleted_post_meta` for media fields; attachment **delete**; ACF field-group changes (fields added/removed/retyped); post-type/taxonomy (de)registration; exclusion-option changes; and capability/role changes. Missing any one yields silently stale/incorrect results — the classic failure mode of cached-index subsystems.
3. **Background reliability.** WP-Cron only fires on traffic (unreliable on low-traffic sites) and is a poor fit for a chunked **first-run full scan**. **Action Scheduler** (robust chunking + retries) is the right tool but adds a dependency. Cheap **synchronous** per-entity updates on targeted hooks are reliable; the full first scan and periodic reconciliation need a real queue.
4. **Storage choice.** Scan results are **derived cache/index data, not source-of-truth content.**
   - *JSON under `wp-content/` (DBVC sync folder):* fits DBVC's backup ethos, but conflates rebuildable cache with content, risks large files, needs file locking for concurrent writes, and is not queryable/indexable for per-row incremental updates.
   - *Custom table:* the right tool for a queryable, incrementally-updatable, per-entity index — indexable, updatable one row at a time, **excludable from content backups**, and versioned via schema migration. DBVC already ships a custom table (`{prefix}dbvc_ve_change_items`), so the pattern exists.
   - **Recommendation:** custom table as the working store; **optional** export-to-JSON for portability/backup — *not* JSON as the primary store.
5. **Consistency/concurrency.** Incremental updates racing a full scan or a user's active remediation session need a consistency model (the per-scan generation/revision handles a session today; a live index needs its own).
6. **Scope.** This is a subsystem comparable in size to R1's scan engine — a **new major phase**, not something to squeeze into R2-E/F/G.

## Recommended path

- **Yes, pursue it — but as a distinct, carefully-scoped phase with its own discovery/decision pass**, sequenced **after** the current R2-E hardening (E4) and the R2-F Slice 4 fix land, so a solid on-demand tool ships first and persistence layers onto a stable base.
- **Storage:** custom table (indexed, incremental, backup-excludable) + optional JSON export.
- **Background:** Action Scheduler when available, else a guarded/chunked WP-Cron fallback; cheap **synchronous** per-entity invalidation on targeted hooks + a periodic full reconciliation.
- **Prerequisite discovery deliverables:** (a) the complete invalidation-event catalog; (b) a redesigned security/generation model for a shared persistent index with **read-time** per-user capability filtering; (c) the table schema + migration/versioning; (d) the queue/cron strategy and its fallback.

## A smaller first step (recommended if we want value sooner)

Rather than the full background-reconciliation engine up front, a lower-risk **Phase 1** delivers most of the "instant + fresh" value:

- Persist the **last completed scan** per site so re-opening the Manager is instant.
- Mark an entity **dirty** on a media-field/attachment change (targeted hooks) and **lazily re-scan just that entity** on next view — no full-site background job.
- Defer the periodic full-site cron reconciliation to a Phase 2 once the invalidation catalog and security redesign are proven.

This sidesteps most of the WP-Cron reliability and full-index consistency risk while still making the Manager feel instant and current.

## Open questions for the maintainer

- Is site-wide, cross-user persistence desired, or is per-user "instant re-open" enough (which keeps the current security model largely intact)?
- Is Action Scheduler acceptable as a dependency, or must this run on stock WP-Cron?
- Should the index be backup-portable (JSON export), or is it acceptable as rebuildable cache excluded from backups?
