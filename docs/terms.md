# Term & Taxonomy Entity Handling

## Goals
- Treat taxonomy terms (including `termmeta`) as first-class entities alongside posts/CPTs.
- Let reviewers Accept/Keep individual term fields just like post fields, including new-term gating.
- Maintain hierarchy and resolver context across exports/imports while keeping logging/UX predictable.

## Current Capabilities

- **Exporter & Manifest data**
  - Every term entry now includes `vf_object_uid`, sanitized `termmeta`, and an `entity_refs` array with UID, `entities/term/{taxonomy}/{slug}`, and taxonomy/ID fallbacks.
  - Parent data ships as `parent_uid`, `parent_slug`, and `parent` (numeric ID). If the parent exists locally during export, its UID is backfilled automatically.
  - Media metadata (`media_refs`) follows the same format as posts, so resolver + bundle workflows stay consistent.

- **React Review UI**
  - `GET /proposals/{id}/entities` surfaces terms and posts. The entity list shows human-friendly titles, taxonomy labels, new-entity badges, and resolver badges for all entity types.
  - The entity drawer exposes taxonomy, slug (`taxonomy/slug` formatting), parent info, file path, Accept/Keep controls, resolver filters, and new-entity gating.
  - Duplicate manifest UI is term-aware, treating term entries the same as posts while displaying UID/slug/type metadata.

- **Importer**
  - Core import path (`DBVC_Sync_Posts::import_backup`) matches terms via UID → taxonomy/slug → taxonomy/ID → `entity_refs` fallback.
  - Existing terms only update when reviewers have Accept/Keep decisions (mirrors post behavior); reopen automation respects `__dbvc_new_entity__`.
  - Parent relationships resolve via UID/slug/ID; if the parent isn’t available at import time, the child queues a “pending parent” entry that is replayed after the import completes.
  - Detailed term logging is available (when logging is enabled) via the new “Include term-specific events in import logs” toggle under **Configure → Import**.

## Platform Alignment

### Media pipeline
- Term entities share the same media export/resolver path as posts. `media_refs` feed existing UI badges, the resolver modal, and bundle ingestion without special casing.

### Review gating & reopen automation
- New terms honor the `__dbvc_new_entity__` decision: imports only run when reviewers click “Accept new entity.” Auto-reapply respects the `dbvc_force_reapply_new_posts` option, re-seeding accepted term UIDs when a proposal reopens.

### Logging & observability
- Import logging can be scoped to term detail: enable “Log content imports” plus “Include term-specific events” to capture granular messages covering invalid payloads, skipped terms, parent resolution, and final apply outcomes.

## Implementation Highlights

1. **Exporter**
   - Adds `entity_refs` to manifest + `entities.jsonl` (UID/slug/ID fallbacks).
   - Captures `parent_uid` where available, ensuring parents are referenceable across environments.
2. **Importer**
   - `identify_local_term()` consumes `entity_refs` to resolve existing terms even when only slug/ID data is present.
   - `apply_term_entity()` handles Accept/Keep gating, term meta, and defers parent assignment via a queue when needed.
   - Logging hooks (`log_term_import`) describe successes, skips, failures, and parent resolution events.
3. **React Admin UI**
   - Entity table and drawer provide taxonomy/slug/parent context for terms.
   - Duplicate modal, Accept/Keep bulk controls, resolver filters, and “Store hashes” flows are term-aware.
4. **Snapshots & Diff Engine**
   - Snapshot manager captures taxonomy entities (UIDs, parents, termmeta) so reviewer diffs compare real local data to the proposal payload instead of defaulting to “no changes.”
   - Accept/Keep gating for reopened proposals honours those snapshots, keeping term workflows identical to post workflows.

## QA / Testing Checklist

1. Export proposals containing new + existing terms (with parent hierarchies and meta) and verify manifests include `entity_refs`, `parent_uid`, and term meta.
2. In the React UI, confirm:
   - Term rows display taxonomy, slug, parent info.
   - Accept/Keep + new-term badges behave like posts.
   - Drawer shows taxonomy/slug/parent/path/resolver info correctly.
3. Import the proposal:
   - Existing terms only apply when selections exist.
   - New terms require `accept_new`.
   - Parent hierarchies persist even when parents/children import out of order (check logs for deferred/resolved parent entries).
   - Logging toggles behave: term logs only appear when both logging + term-specific checkbox are enabled.
4. Reopen the proposal and confirm previously accepted terms are auto-marked for import (`dbvc_force_reapply_new_posts`).

## Notes & Edge Cases
- **Slug conflicts:** Duplicate slugs across taxonomies still need reviewer intervention; the duplicate modal now displays term metadata to help resolve collisions quickly.
- **CLI parity:** WP-CLI imports reuse the same code path; enable logging to trace term events when running automated jobs.
- **Performance:** Large taxonomies may produce many entities; virtualization and search/filtering are already handled, but keep an eye on parent queues during large imports.
- **Legacy proposals:** Term snapshots landed after 1.3.4. Re-upload older proposal zips, invoke `DBVC_Snapshot_Manager::capture_for_proposal($proposal_id, $manifest)`, or run `wp dbvc proposals list --recapture-snapshots=<ids>` so reopened reviews diff against the current site instead of treating every term as new.
- **Deletion behavior:** Term deletion removes the JSON file (when present) and clears the matching entity registry row. Terms do not have a trash state, so deletes are permanent.

With these pieces in place, term/taxonomy objects behave exactly like posts throughout export, review, and import workflows, providing full cross-environment parity.
