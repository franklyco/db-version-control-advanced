# DBVC — Media Mapping, Diff & “Official” Promotion  
**Implementation Handoff (drop-in for Codex / plugin reference)**  
_Last updated: 2025-11-06 (America/New_York)_

---

## 0) Scope

### Goals
- Stable cross-site identity for **posts, terms, attachments**.
- Deterministic **media mapping** (ID remap) during import.
- Zip-based **proposal** format (`manifest + entities.jsonl + media/`).
- Server-side **preflight** that builds an ID map and computes diffs.
- Admin UI to **review diffs**, **accept/keep** per field, and **mark official**.
- “Official” **collections** exportable as zip and stored in `/uploads/dbvc/official`.

### Non-Goals
- Full site migration, search index sync, or user/role sync.
- External asset registry service (future enhancement).

### Implementation Status (2025-11-06)
- **Identity layer** — ✅ Shipped. Posts/terms/attachments receive `vf_object_uid`/`vf_asset_uid`, persisted via `DBVC_Sync_Posts` hooks + `wp_dbvc_entities`.
- **Media resolver** — ✅ Live resolver (`DBVC_Media_Sync` + resolver REST) handles duplicates, downloads, and global rules; duplicate blocking + cleanup APIs enforced in the React app.
- **Exporter / Diff / REST + React UI / Official collections** — ✅ Exporter + manifest writer + React workflow are production-ready (proposal upload, diff review, Accept/Keep, resolver tooling). Official “collections” export remains future work.
- **Apply & CLI parity** — ✅ Apply pipeline honours decisions/duplicates/new-entity gating; CLI parity for the new workflow is still pending (legacy WP-CLI commands remain available).

---

## 1) Architecture Overview

### Phases (recommended order)
1. **Identity layer** (UIDs for posts/terms/attachments; backfill + auto-stamp). _Status: ✅ Complete (vf_object_uid auto-stamp + `wp_dbvc_entities` registry)_  
2. **Media resolver** (UID/hash/path → attachment ID map; upload if missing). _Status: ✅ Complete (resolver REST + duplicate cleanup + media bundle ingestion)_  
   - UX follow-up: add a dedicated “Media Handling” subtab under Configure that centralizes all media toggles (bundle exports, remote downloads, filename preservation). Keep Export-tab checkboxes in sync with this subtab so admins always change a single source of truth.
3. **Exporter** (normalized entity snapshots + manifest + optional media). _Status: ✅ Complete (manifest writer + bundle support shipping today)_  
4. **Diff engine** (type-aware comparisons for core/meta/ACF/blocks/tax). _Status: ✅ Complete (entity snapshots + React diff + Accept/Keep pipeline)_  
5. **REST + UI** (proposal list → drill-down → per-field accept/keep). _Status: ✅ Complete (React admin app, duplicate modal, new-entity gating)_  
6. **Official collections & export** (snapshot store + zip export). _Status: planned_  
7. **Apply engine + CLI parity** (write decisions; strategies; logs). _Status: 🚧 Apply is live; CLI parity pending._  
8. **Hardening** (perf, security, tests). _Status: ongoing_

---

## 2) Identity Layer

### Meta keys (source of truth)
- **Posts/CPTs**: `vf_object_uid` (UUIDv4, string)
- **Terms**: `vf_object_uid` (UUIDv4, string)
- **Attachments**:  
  - `vf_asset_uid` (UUIDv4, string)  
  - `vf_file_hash` (SHA-256, hex string of original file)  
  - (opt) `vf_rel_path` (copy of `_wp_attached_file`)

### Optional index tables (for speed; mirrors meta)
```sql
CREATE TABLE IF NOT EXISTS wp_dbvc_entity_uid (
  entity_uid CHAR(36) NOT NULL,
  entity_type ENUM('post','term','attachment') NOT NULL,
  local_id BIGINT UNSIGNED NOT NULL,
  post_type VARCHAR(64) NULL,
  taxonomy VARCHAR(64) NULL,
  slug VARCHAR(200) NULL,
  last_seen_gmt DATETIME NULL,
  PRIMARY KEY (entity_uid),
  KEY k_type (entity_type, local_id),
  KEY k_slug (slug)
) DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wp_vf_asset_index (
  asset_uid CHAR(36) NOT NULL,
  attachment_id BIGINT UNSIGNED NOT NULL,
  file_hash CHAR(64) NOT NULL,
  rel_path VARCHAR(255) NULL,
  mime_type VARCHAR(100) NULL,
  filesize BIGINT NULL,
  width INT NULL, height INT NULL,
  last_verified DATETIME NULL,
  PRIMARY KEY (asset_uid),
  KEY k_hash (file_hash),
  KEY k_rel (rel_path)
) DEFAULT CHARSET=utf8mb4;
```

### Auto-stamping hooks (skeletons)
- `add_action('save_post', ...)` → ensure `vf_object_uid`
- `add_action('created_term')` / `edited_term` → ensure `vf_object_uid`
- `add_action('add_attachment', ...)` → ensure `vf_asset_uid` + `vf_file_hash`

---

## 3) Export Artifacts (Proposal Zip)

```
proposal.zip
 ├─ dbvc-manifest.json
 ├─ entities.jsonl      (one JSON object per line)
 └─ media/              (optional binaries if new/missing)
```

### `dbvc-manifest.json` (schema excerpt)
```json
{
  "version": "1.0",
  "generated_at": "2025-11-05T23:00:00Z",
  "site": { "domain": "local.test", "wp": "6.6.x", "php": "8.2" },
  "counts": { "posts": 120, "terms": 34, "attachments": 12 },
  "strategies": { "conflict": "prompt|prefer-current|prefer-proposed" },
  "media_policy": { "bundle_new": true, "include_existing": false }
}
```

### `entities.jsonl` (per-entity normalized snapshot)
_Post example line_
```json
{
  "entity_type": "post",
  "vf_object_uid": "7b8e721b-bc1a-41e8-a6a4-0c3c74f951e1",
  "post_type": "service",
  "slug": "tpo-flat-roof-repair",
  "status": "publish",
  "title": "TPO Flat Roof Repair",
  "content_raw": "<!-- wp:paragraph --> ...",
  "excerpt": "",
  "post_modified_gmt": "2025-11-04T21:10:11Z",
  "tax_input": { "service_area_tax": ["Akron","Canton"] },
  "meta": {
    "_thumbnail_id": 901,
    "field_abc123": 456
  },
  "acf_schema": { "field_abc123": "image" },
  "block_refs": [
    { "path": "0/2", "type": "core/image", "local_id": 901 }
  ],
  "dbvc_post_history": {"last_actor":"rhett","last_reason":"content fix"}
}
```
_Term example line_
```json
{
  "entity_type": "term",
  "vf_object_uid": "f0f1f9a2-447f-49d6-8a58-0e2b9afc3d44",
  "taxonomy": "service_area_tax",
  "slug": "akron",
  "name": "Akron",
  "description": "Service area blurb",
  "termmeta": { "field_xyz": "..." },
  "modified_gmt": "2025-11-01T12:00:00Z"
}
```

**Notes**
- ACF values should be keyed by **field key** for stability; UI can resolve keys → labels.

---

## 4) Preflight Resolver (server-side)

### Matching rules
1) **Posts/Terms**: match by `vf_object_uid`; fallback (`post_type, slug`) or (`taxonomy, slug`) then assign UID.  
2) **Attachments**: `vf_asset_uid` → `vf_file_hash` → `_wp_attached_file` (relative path). If unmatched and file exists in `/media`, upload, then stamp UID/hash.

- **Order & collisions**: treat the lookup as strict priority (UID first). If multiple local attachments share the same hash/path, stop the resolver, flag the attachment as a conflict for human review, and fall back to upload only when the bundled asset hash matches the manifest.  
- **Unresolved outcomes**: if no match is found and the bundle lacks the binary, surface `media.unresolved[]` so the UI can request a manual upload or external fetch (legacy `DBVC_Media_Sync` can supply the download helper until the new resolver lands).

### Output mapping (for UI + apply)
```json
{
  "id_map": {
    "posts": { "7b8e...e1": 123 },
    "terms": { "f0f1...d44": 77 },
    "attachments": { "7e6b...a1": 901 }
  },
  "media": { "to_upload": 2, "resolved": 10, "unresolved": 0 }
}
```

---

## 5) Diff Engine

### Normalization
- Cast scalar types (int/bool).
- Sort sets where order doesn’t matter (taxonomy terms).
- Strip env-specific URLs from content where feasible.
- Parse **blocks** (`parse_blocks`), map incoming image IDs to target IDs, then compare block trees.
- For **ACF**, compare by **field key**. For repeaters/flex, diff recursively by index.

### Ignore list (do not diff)
`_edit_lock`, `_edit_last`, `_wp_old_slug`, cache/transient keys, nonces, runtime plugin flags.

### Diff result schema (per entity)
```json
{
  "vf_object_uid": "7b8e...e1",
  "entity_type": "post",
  "target_id": 123,
  "summary": { "fields_changed": 5, "severity": "minor|major" },
  "fields": [
    { "path": "title", "from": "Old", "to": "New" },
    { "path": "meta.field_abc123", "type": "image", "from_id": 901, "to_id": 456 },
    { "path": "tax.service_area_tax", "type": "set", "added": ["Canton"], "removed": [] },
    { "path": "blocks.0/2.attrs.id", "from": 901, "to": 456 }
  ]
}
```

---

## 6) REST API (admin-only)

Base: `/wp-json/dbvc/v1`

- `POST /proposals` — upload zip  
  **Body**: file multipart  
  **Resp**: `{ "proposal_id": "pr_123", "summary": { ... } }`

- `GET /proposals/{id}/summary`  
  **Resp**: counts, media status, filters.

- `GET /proposals/{id}/entities?type=post&post_type=service&severity=major&page=1`  
  **Resp**: paged list with diff summaries.

- `GET /proposals/{id}/entities/{vf_object_uid}`  
  **Resp**: full diff result + normalized current/proposed snapshots (server-redacted as needed).

- `POST /selections`  
  **Body**: `{ "proposal_id":"pr_123", "vf_object_uid":"...", "decisions":[{"path":"meta.field_abc123","action":"accept|keep"}] }`  
  **Resp**: `{ "ok": true }`

- `POST /mark-official`  
  **Body**: `{ "proposal_id":"pr_123", "vf_object_uids":["...","..."], "collection_title":"Nov 5 Hotfix" }`  
  **Resp**: `{ "collection_id": 42 }`

- `POST /export-official`  
  **Body**: `{ "collection_id":42 }`  
  **Resp**: `{ "download": "/wp-content/uploads/dbvc/official/Nov-5-Hotfix.zip" }`

- `GET /proposals` — list staged proposals for review (metadata: id, title, counts, generated_at, resolver metrics).
- `GET /proposals/{id}/resolver` — return resolver payload (`metrics`, `conflicts`, `id_map`, `attachments[]`) to power media status UI. _Prototype resolves manifest on the fly via `Dbvc\Media\Resolver::resolve_manifest`._
- `GET /proposals/{id}/entities` — paginated diff listings (supports filters: type, severity, search, post_type/taxonomy). _Prototype streams manifest items and annotates each row with resolver status + attachment summary._
- `GET /proposals/{id}/entities/{vf_object_uid}` — full diff detail (current/proposed snapshots, flattened diff summary).
- `POST /proposals/{id}/selections` — persist per-field decisions; responds with updated entity summary + remaining counts.
- `POST /proposals/{id}/entities/{vf_object_uid}/selections` — store per-field decision (`accept|keep`) keyed by diff path; decisions persist in option `dbvc_proposal_decisions` and return updated summaries.
- `POST /proposals/{id}/apply` — run the import pipeline for the selected proposal (default mode: `full`). Request body supports `{ "mode":"full|partial", "ignore_missing_hash":true }`. Response includes imported/skipped counts, resolver metrics, resolver decision summary, and updated field decisions.
- `POST /proposals/{id}/resolver/{original_id}` — save per-attachment decision `{action:"reuse|download|map|skip",target_id?,note?,persist_global?}`.
- `DELETE /proposals/{id}/resolver/{original_id}` — clear resolver decision; `scope=global` removes promoted rules.
- `GET /resolver-rules` — list global resolver rules (persisted decisions available across proposals).
- `DELETE /resolver-rules/{original_id}` — delete a global resolver rule.
- Response shape reference (prototype):
  - `GET /proposals`: `{ "items": [{ "id": "2025-11-06-050533", "generated_at": "...", "files": 548, "media_items": 140, "missing_hashes": 147, "locked": false, "size": 123456, "resolver": { "metrics": {...} }, "decisions": { "accepted": 12, "kept": 4, "total": 16, "entities_reviewed": 5, "entities_with_accept": 3 } }] }`
  - `GET /proposals/{id}/entities`: `{ "proposal_id": "...", "items": [{ "vf_object_uid": "21651", "post_id": 21651, "post_type": "alternative", "post_title": "SquareSpace...", "path": "...json", "hash": "...", "content_hash": "...", "media_refs": {...}, "resolver": { "status": "needs_review", "summary": { "total": 2, "resolved": 1, "unresolved": 1, "conflicts": 0, "unknown": 0 }, "attachments": [...] }, "decision_summary": { "accepted": 3, "kept": 1, "total": 4, "has_accept": true } }], "resolver": { "metrics": {...} }, "decision_summary": { "accepted": 12, "kept": 4, "total": 16, "entities_reviewed": 5, "entities_with_accept": 3 } }`
  - `GET /proposals/{id}/resolver`: `{ "proposal_id": "...", "metrics": {...}, "conflicts": [], "id_map": {"asset_uid": 123}, "attachments": { "asset_uid": {...resolution...} } }`
  - `POST /proposals/{id}/entities/{vf_object_uid}/selections`: body `{ "path":"meta.field_abc123", "action":"accept|keep" }` → response `{ "proposal_id":"...", "vf_object_uid":"...", "decisions": { "meta.field_abc123":"accept" }, "summary": { "accepted": 1, "kept": 0, "total": 1, "has_accept": true }, "proposal_summary": { "accepted": 12, "kept": 4, "total": 16, "entities_reviewed": 5, "entities_with_accept": 3 } }`
  - `POST /proposals/{id}/apply`: body `{ "mode":"full" }` → response `{ "proposal_id":"...", "mode":"full", "result":{ "imported":12,"skipped":3,"errors":[],"media":{...},"media_resolver":{...} }, "decisions_before":{...}, "decisions":{...}, "auto_clear_enabled":true, "decisions_cleared":true }`

**Security**: capability `manage_options`, nonces per write endpoint.

---

## 7) Admin UI (React)

**Status**: planned — current production UI remains the PHP-based backup/import screen in `admin/admin-page.php`.

### Screen: **DBVC → Diff & Promote**
- **Header**: Upload proposal (drag-drop). Recent proposals list.
- **Summary cards**: Posts w/ diffs, Terms w/ diffs, Media to upload, Conflicts.
- **Filters**: entity type, post type/taxonomy, severity, actor/date. Entity table defaults to “Needs Review” so conflicts bubble to the top.
- **Grid**: virtualized rows → click drills into entity.
- **Decision badges**: proposal list and entity grid surface Accept/Keep counts derived from `dbvc_proposal_decisions`; detail panel shows a running selection summary for the active entity.
- **Diff view**: defaults to a “Conflicts & Resolver” filter that shows resolver/media-related diffs first; reviewers can toggle to the full overview without scrolling.
- **Resolver panel**: each attachment row exposes `Reuse / Download / Map / Skip` actions with optional target IDs, notes, and a “remember for future proposals” toggle. Decisions persist in `dbvc_resolver_decisions` and render inline badges/states.
- **Bulk resolver helpers**: once a decision is saved, “Apply to similar” copies it to other conflicts sharing the same reason; toast/history/apply messages now show resolver decision counts and unresolved conflicts.
- **Actions**: “Apply Proposal” launches a confirmation modal, lets the reviewer pick `full` vs `partial` import modes, optionally skip hash validation for legacy manifests, then calls `POST /proposals/{id}/apply`; a toast surfaces the outcome immediately, and a recent-activity log captures the last few apply runs (mode, counts, errors, decision-clear flag, hash override).

### Drill-down (entity panel)
- **Overview**: Title/slug/type/status, modified times (current vs proposed).
- **Tabs**:
  1. **Side-by-Side** diff (core/meta/ACF/blocks/tax sections).  
     - Per-field **Accept proposed** / **Keep current** toggles, plus bulk **Accept All Visible** / **Keep All Visible** actions that respect the active diff filter.  
     - Image preview with **current ID → mapped ID** badges.
  2. **History** (`dbvc_post_history`).

- **Actions**: “Accept All”, “Keep Current”, “Mark Official”.

**A11y**: keyboard focus traps in modals, ARIA roles on diff tables, color-independent change markers.

### New difference comparison feature
- **Goal**: give reviewers a trustworthy, field-aware diff between the proposal snapshot and live object so they can approve/decline with confidence.
- **Scope**: posts + terms (attachments appear only as referenced media previews); other entity types stay out-of-scope for v1.
- **Entry point**: selecting a row in the summary grid loads the comparison panel.
- **Layout**: side-by-side columns (“Current Site” vs “Proposed Import”) with a persistent header showing entity basics (type, title/name, status, last modified).
- **Diff presentation**:
  - Render changed scalar fields with inline highlighting; show old/new values stacked for readability.
  - For rich text (`content_raw`, term descriptions), provide a collapsed summary plus an expandable block-level diff view.
  - Taxonomies/meta/ACF groups render as structured lists; each changed item highlights additions/removals.
  - Image/meta references include thumbnails by using the attachment ID map and indicating mapped IDs.
  - Section navigator reduces scroll fatigue; reviewers can focus a single section or expand all as needed.
- **Interactions**:
  - Per-field toggles (`Accept proposed` / `Keep current`) remain, but now sit directly inside the diff card for that field.
  - “Accept All”/“Keep Current” buttons respect current filter state and surface a confirmation toast.
  - Provide a `View JSON` drawer to inspect the normalized payloads for troubleshooting.
- **Data dependencies**:
  - `GET /proposals/{id}/entities/{vf_object_uid}` returns `current`, `proposed`, `diff.fields[]`, and `media` references (existing contract, ensure completeness).
  - `POST /selections` accepts per-field decisions, and must return updated diff summary (`fields_pending`, etc.) for optimistic UI updates.
  - Preflight response must expose `media.id_map` so the UI can resolve thumbnails without extra round-trips.
- **Architecture notes**:
  - React SPA mounted via `admin/class-admin-app.php`, bundle expected under `build/admin-app.*`. Current integration injects the React root at the top of `dbvc_render_export_page()` so legacy PHP controls remain available underneath until feature parity is achieved.
  - Suggested component tree: `App` → `ProposalList` → `DiffGrid` → `EntityDetail` (tabs for Diff / Resolver / History).
  - Use context/provider for proposal state and keep resolver payload cached per proposal to avoid redundant REST calls.
  - Local state map: `AppState = { proposals, selectedProposalId, filters, entityPage, entityCache, resolverCache, search }`.
  - `DiffGrid` consumes enriched `entities` payload; each row shows resolver status/badges, unresolved counts, and conflicts.
  - `EntityDetail` pulls `GET /proposals/{id}/entities/{vf_object_uid}` for grouped diff + resolver attachments.
- Use SWR/React Query (optional) or simple `useEffect` + fetch to hydrate data; keep resolver output in a memoized cache keyed by proposal ID.
- Filters: UI currently supports `all|needs_review|resolved` via REST `status` param plus a text search against title/type/path; expand to severity/actor/date once API surfaces metadata.
- Build system: plan to use `@wordpress/scripts` or Vite for bundling; output to `build/admin-app.js(.css)` with accompanying `admin-app.asset.php`.
- Dev workflow: add npm-based build (scripts: `start`, `build`), rely on `wp-scripts start` for dev server; ship transpiled bundle with plugin release.
- Placeholder UI (`src/admin-app/index.js`) currently renders proposal table sourced from new REST endpoints—replace with production components once design is ready.
- Diff view renders grouped collapsible sections (meta/tax/media/content) with inline highlights (`<mark>`) for changed substrings; raw JSON remains visible for debugging until we add prettified field renderers.
- Per-field decisions (Accept/Keep) persist via `POST /proposals/{id}/entities/{vf_object_uid}/selections`; prototype stores in option `dbvc_proposal_decisions`.
- **Error states**: network/parse errors show a retry prompt without losing the reviewer’s pending decisions.
- **A11y/UX**: diff cards keyboard navigable, visible focus states, announce context when toggles change.

---

## 8) “Official” Collections

### Storage
- CPT `dbvc_collection` (title as label, e.g., “Nov-05-Prod-Hotfix”)
- Table:
```sql
CREATE TABLE IF NOT EXISTS wp_dbvc_official_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  collection_id BIGINT UNSIGNED NOT NULL,
  entity_uid CHAR(36) NOT NULL,
  entity_type ENUM('post','term') NOT NULL,
  target_id BIGINT UNSIGNED NOT NULL,
  snapshot_path VARCHAR(255) NOT NULL,
  created_gmt DATETIME NOT NULL,
  UNIQUE KEY u1 (collection_id, entity_uid)
) DEFAULT CHARSET=utf8mb4;
```

### Snapshots
- Persist resolved, **post-decision** snapshots to:  
  `/wp-content/uploads/dbvc/official/{collection}/{entity_uid}.json`

### Export
- `entities.jsonl` (from snapshots), `dbvc-manifest.json`, optional `media/`.

---

## 9) Apply Engine

- Strategies: `prefer-current` | `prefer-proposed` | `prompt-per-field` (UI default).
- Write order per post:  
  1) Core fields (`wp_update_post`).  
  2) Taxonomies (`wp_set_object_terms` by resolved term IDs).  
  3) Meta/ACF (prefer ACF APIs where safe; else `update_post_meta`).  
  4) Blocks: `parse_blocks` → apply ID/url changes → `serialize_blocks` → `wp_update_post`.
- **Selective apply (v1 UI)**: `DBVC_Sync_Posts::import_backup()` now hydrates `dbvc_proposal_decisions` for the active proposal. When an entity has reviewer selections, only diff paths flagged `accept` are staged; missing/`keep` paths are left untouched. Entities with no accepted paths are skipped (counted separately from errors). New entities (no local post yet) still import in full.
- **Auto-clear toggle**: Setting `dbvc_auto_clear_decisions` (Configure → Import) defaults to on; when enabled, `import_backup()` removes the proposal’s stored selections after a successful, error-free import and writes a log entry.
- **Apply endpoint**: `POST /proposals/{id}/apply` wraps `import_backup()` (default `mode: full`) and returns imported/skipped counts, media stats, and updated decision summaries to the React UI. When `ignore_missing_hash` is true (used with partial applies), the importer bypasses the missing-hash guard for legacy backups.
- **Planned**: Persist an audit/history log of reviewer selections (proposal/entity/path/action/user/timestamps) once we introduce the history UI.
- Transactions: per entity best-effort (or batch) with rollback on failure.
- Logging: one log row per entity with changed paths.

---

## 10) Media Resolver Actions (next phase design)

The admin app currently surfaces resolver metrics and attachment rows (status, reason, target). Reviewers need to react to conflicts such as `duplicate_hash` before running an apply. Proposed workflow:

- **Status**: initial API + UI wiring complete for per-attachment decisions. Outstanding: feed decisions into importer + add optional batch endpoints.

### Data model / backend
- `dbvc_resolver_decisions` option (parallel to field decisions) keyed by `{proposal_id}.{original_id}` storing `{action: "reuse|download|map|skip", target_id?: int, note?: string, scope: "proposal|global"}` with timestamps/users.
- `DBVC_Admin_App::get_proposal_entities()` and `get_proposal_resolver()` merge saved decisions into resolver payloads so UI can show prior choices.
- REST endpoints:
  - `POST /proposals/{id}/resolver/{original_id}` (body: `{ action, target_id?, note?, persist_global? }`) persists a decision and returns the latest state.
  - `DELETE /proposals/{id}/resolver/{original_id}?scope=proposal|global` clears a stored decision.
- Update `DBVC_Sync_Posts::import_backup()` (and `DBVC_Media_Sync::sync_manifest_media`) to honor stored decisions:
  - `reuse`: map to chosen attachment ID without downloading.
  - `download`: force download even if resolver thinks it’s a duplicate.
  - `map`: explicit new target ID (for duplicate hash / cross-site mapping).
  - `skip`: leave unresolved and surface warning after apply.
- Log resolver decisions (and whether they were honored) in `dbvc_backup.log` for audit.

### Front-end UX
- Resolver table gains inline actions per conflict row:
  - Radio/segmented control for `Reuse existing`, `Download new`, `Map to…`, `Skip`.
  - When `Map to…` selected, accept manual attachment ID input (future enhancement: searchable select).
  - Display current selection summary/state badges (e.g., “Will reuse #1234”).
- Bulk helpers:
  - “Apply to all duplicate hash conflicts” button that copies the current decision to every matching conflict (calls batch API).
  - Chip/filters to focus on unresolved, pending decision, overridden decisions.
- Integrate with apply history/toasts: if apply encounters unresolved conflicts, include them in the result message; when decisions exist, note they were honored.
- Ensure resolver decisions appear in the new activity log alongside field decisions for the proposal.

### Open questions
- Do we need versioning for resolver decisions (e.g., when a new manifest replaces the same proposal ID)?
- Should decisions persist across proposals (global mapping rules) or stay proposal-scoped?
- How should we expose target attachment search—WP REST `/wp/v2/media` or custom query?

Implementation can start once we align on the data contract and desired resolver actions.

---

## 11) CLI (WP-CLI)

```
wp dbvc backfill-uid --posts --terms --attachments
wp dbvc export --posts=12,34 --terms=akron,canton --with-media --out=/path/to/proposal.zip
wp dbvc import /path/to/proposal.zip --dry-run --strategy=prefer-current
wp dbvc import /path/to/proposal.zip --apply --strategy=prompt
wp dbvc export-official --collection="Nov-05-Prod-Hotfix" --with-media
wp dbvc verify-assets
```

---

## 11) Hooks & Filters

```php
/**
 * Filter ignore list of meta keys for diffing.
 * @param string[] $keys
 */
apply_filters('dbvc_diff_ignore_meta_keys', $keys);

/**
 * Map (post_type, slug) → vf_object_uid on preflight (override matching).
 */
apply_filters('dbvc_match_post_uid', $maybe_uid, $post_type, $slug);

/**
 * Decide whether to upload a missing asset.
 */
apply_filters('dbvc_media_should_upload', true, $asset_meta, $manifest);

/**
 * Post-apply callback per entity.
 */
do_action('dbvc_entity_applied', $vf_object_uid, $entity_type, $result);
```

---

## 12) Logging, Debug, Security

- **Debug flag**: `DBVC_DEBUG` or options page toggle → verbose logs to `wp-content/uploads/dbvc/logs/`.
- **PII**: snapshots contain content; store under authenticated paths; deny direct listing via `.htaccess`/Nginx rules.
- **Caps/Nonces**: restrict REST/CLI to admins; verify nonces for uploads and mutations.

---

## 13) Performance

- Batch sizes: 50–200 entities per page in REST; virtualize lists in UI.
- Indexes on custom tables (`entity_uid`, `file_hash`, `rel_path`).
- Cache block parsing where unchanged.
- Avoid regex on serialized arrays; use ACF/WordPress APIs wherever possible.

---

## 14) Testing Matrix

- **Unit**: normalizers, diff paths, media resolver fallbacks (UID→hash→path).
- **Integration**: import preflight on mixed new/existing media; ACF complex fields (repeater, flex).
- **E2E**: proposal upload → diff → per-field decisions → mark official → export → re-import on another site.
- **Edge**: `-scaled` images, duplicate hashes, slug collisions, term renames.

---

## 15) Class & File Scaffolding (suggested)

```
/plugins/dbvc/
  dbvc.php
  /includes/
    /Dbvc/
      Bootstrap.php
      Normalize/
        EntityNormalizer.php
      Media/
        Resolver.php
        Hashing.php
      Diff/
        Engine.php
        Result.php
      Export/
        Writer.php
      Import/
        Preflight.php
        Apply.php
      Official/
        Collections.php
        Exporter.php
      Rest/
        Controller_Proposals.php
        Controller_Entities.php
        Controller_Collections.php
      Cli/
        Backfill.php
        Export.php
        Import.php
        OfficialExport.php
      Admin/
        App.php   (enqueues React app, capabilities)
  /assets/admin/
    app.js (React)
    app.css
```

### Key class stubs

```php
namespace Dbvc\Media;
final class Resolver {
  public static function stamp_attachment_identity(int $attachment_id): void {}
  public static function build_id_map(array $manifest, string $media_dir): array {} // returns id_map
}

namespace Dbvc\Normalize;
final class EntityNormalizer {
  public static function from_post(int $post_id): array {}
  public static function from_term(int $term_id): array {}
}

namespace Dbvc\Diff;
final class Engine {
  public static function compare(array $current, array $proposed): array {} // DiffResult schema
}

namespace Dbvc\Import;
final class Preflight {
  public static function run(string $zip_path): array {} // summary + id_map + diffs
}
final class Apply {
  public static function apply_entity(array $decisions, array $context): array {} // result
}

namespace Dbvc\Official;
final class Collections {
  public static function mark_official(string $proposal_id, array $uids, string $title): int {}
  public static function snapshot_entity(array $resolved_snapshot, int $collection_id): void {}
}
final class Exporter {
  public static function export_collection(int $collection_id, bool $with_media = true): string {}
}
```

---

## 16) Pseudocode Snippets

### Stamp attachment identity
```php
function vf_register_attachment_identity($attachment_id) {
  $path = get_attached_file($attachment_id);
  if (!$path || !file_exists($path)) return;

  if (!get_post_meta($attachment_id, 'vf_asset_uid', true)) {
    update_post_meta($attachment_id, 'vf_asset_uid', wp_generate_uuid4());
  }
  if (!get_post_meta($attachment_id, 'vf_file_hash', true)) {
    update_post_meta($attachment_id, 'vf_file_hash', hash_file('sha256', $path));
  }
}
add_action('add_attachment', 'vf_register_attachment_identity');
```

### Resolve attachment by UID/hash/path
```php
function dbvc_find_attachment_id($uid, $hash, $rel_path=null): int {
  if ($uid) { /* WP_Query meta vf_asset_uid */ }
  if ($hash) { /* WP_Query meta vf_file_hash */ }
  if ($rel_path) { /* WP_Query meta _wp_attached_file */ }
  return 0;
}
```

### Block remap
```php
function dbvc_remap_blocks($content, $id_map) {
  $blocks = parse_blocks($content);
  $changed = false;
  $walk = function (&$b) use (&$walk, &$changed, $id_map) {
    if (!empty($b['attrs']['id'])) {
      $old = (int)$b['attrs']['id'];
      if (isset($id_map[$old])) { $b['attrs']['id'] = (int)$id_map[$old]; $changed = true; }
    }
    foreach ($b['innerBlocks'] ?? [] as &$ib) $walk($ib);
  };
  foreach ($blocks as &$block) $walk($block);
  return $changed ? serialize_blocks($blocks) : $content;
}
```
---

## 17) Acceptance Criteria (per phase)

**Identity**
- New/edited entities auto-receive UIDs; CLI backfill completes with report.
- Duplicate UID detection guarded and logged.

**Media**
- Preflight resolves ≥95% of attachments via UID/hash/path; remaining prompt for upload.
- `id_map.attachments` produced deterministically.

**Exporter**
- Zip contains valid `dbvc-manifest.json` and `entities.jsonl` with N lines = N entities.

**Diff**
- Changes in title, ACF image fields, taxonomy sets, and image blocks are detected and correctly scoped (`path`).

**UI**
- Upload proposal → summary appears.  
- Drill-down shows side-by-side with per-field Accept/Keep toggles.  
- “Mark Official” creates a collection with stored snapshots.

**Official export**
- Export zip from collection re-imports cleanly on another site with identical results in dry-run.

**Apply**
- `--strategy=prefer-current|prefer-proposed|prompt` works; logs contain changed paths.

---

## 18) Config Defaults (TBD but recommended)
```php
define('DBVC_DEBUG', false);
define('DBVC_MAX_BATCH', 100);
define('DBVC_MEDIA_POLICY_BUNDLE_NEW', true);
```

---

## 19) Developer Notes
- Diff by **ACF field key**; display field **labels** in UI for humans.
- Always trust **ID maps** (not URLs) for setting image references.
- Normalize, then diff. Avoid regex over serialized meta—prefer ACF APIs.
- Keep custom tables as **indexes**; meta is the canonical store.

---

## 20) Transition Tasks (next steps)
- Keep the legacy `DBVC_Media_Sync` pathway operational until the new resolver covers every attachment case; document any gaps discovered during analysis.
- Draft the detailed scope for the upcoming UI enhancement and backfill this handoff with UX notes + endpoint requirements.
- Audit existing WP-CLI commands/logging so we can map them onto the new apply/diff workflow when implementation starts.

---

## 21) Media Mapping Redevelopment Plan

**Objective**: replace the ad-hoc `DBVC_Media_Sync` download/rewrite flow with a deterministic resolver that lines up with the ID-map-first import pipeline.

### Phase 0 — Discovery & instrumentation
- Catalogue current behavior: option flags, bundle generation, URL map usage, and when posts/meta updates fire (`includes/class-media-sync.php`).
- Trace call sites (admin UI, backup restore, CLI) to understand expectations; log real-world events using `DBVC_Sync_Logger::log_media()` so we know how sites rely on the helper today.
- New logging events (`media_sync_candidates`, `media_preview_generated`, `media_sync_completed`) now capture queue sizes, host spread, blocked reasons, relative path usage, and queue attrition (initial vs remaining). Enable media logging to gather full datasets.
- Early sample (2025-11-06 import): `skipped_existing=125`, `queued_initial=125`, `queued_remaining=0`, `downloaded=125`, `meta_updates=327` — indicates the helper currently redownloads every asset and rewrites meta, giving us a baseline for measuring improvements.

### Phase 1 — Contract design
- Define the canonical attachment identity contract (`vf_asset_uid`, `vf_file_hash`, relative path) and document required fields in manifests. Proposal:
  - **Manifest media entry**:  
    ```json
    {
      "asset_uid": "uuid-v4",
      "file_hash": "sha256:hex",
      "relative_path": "2025/10/example.jpg",
      "filename": "example.jpg",
      "filesize": 123456,
      "mime_type": "image/jpeg",
      "dimensions": {"width": 1200, "height": 800},
      "bundle_path": "media/2025/10/example.jpg",
      "source_url": "https://origin/uploads/2025/10/example.jpg"
    }
    ```
  - **Resolver options**: `{ "allow_remote": bool, "write_missing_hash": bool, "dry_run": bool }`.
- Specify resolver outputs:
  - `attachments`: map of `asset_uid` (or manifest key) → `['status' => 'reused|downloaded|conflict|missing', 'target_id' => 123, 'reason' => 'hash_mismatch', 'resolved_via' => 'uid|hash|path', 'expected_hash' => "...", 'actual_hash' => "...']`.
  - `id_map`: flat `asset_uid` → attachment ID array for downstream diff/apply.
  - `conflicts`: structured list of unresolved/mismatched assets (with guidance for UI remediation).
  - `metrics`: counts for reused, downloaded, unresolved, blocked, bundle_hits.
- Ensure REST endpoints surface resolver summaries so the diff UI can display per-entity media health (preflight summary → UI cards).
- Decide how to handle edge cases:
  - Duplicate hashes → mark as conflict with list of candidate attachment IDs; resolver should not auto-pick.
  - Missing bundles but remote allowed → flag and schedule fetch; remote disallowed → stay unresolved.
  - Dimension mismatch when hash matches (e.g., scaled images) → allow reuse but note discrepancy for QA.
  - External URLs blocked → surface explicit `blocked_host` reason so options/UI can prompt admins.

### Phase 2 — Resolver implementation
- Build a namespaced service (`Dbvc\Media\Resolver`) that:
  1. Looks up attachments in priority order (UID → hash → `_wp_attached_file`).
  2. Produces deterministic mappings and raises explicit conflict objects.
  3. Hands unresolved items to a pluggable fetcher (temporary bridge to `DBVC_Media_Sync` download logic).
- Add hooks/filters for override strategies (`dbvc_media_resolver_candidates`, etc.).
- **Status**: resolver now matches via UID → hash → relative path and records conflicts/metrics; importer runs a dry-run alongside the legacy sync to compare outcomes (`includes/Dbvc/Media/Resolver.php`, `includes/class-sync-posts.php:730`). Next step is to consume the returned `id_map` in apply flow and phase out duplicate lookups in `DBVC_Media_Sync`.

### Phase 3 — Integration & migration
- Swap preflight/import code to use the new resolver; keep legacy options for fallback until confidence is high.
- Update admin UI to read the richer resolver status (conflicts, unresolved, fetched) and expose remediation actions.
- Provide a one-time migration routine that clears obsolete temp options (`dbvc_media_map_temp`, `dbvc_media_url_map_temp`) once new mapping is authoritative.

### Phase 4 — Decommission legacy helper
- Gate `DBVC_Media_Sync` behind a feature flag; once telemetry shows no fallbacks, remove duplicate logic.
- Archive final behavior in docs/changelog so downstream adopters know how to adapt.

### QA considerations
- Unit tests around lookup permutations, collision detection, and deterministic outputs.
- Integration tests: proposal with mixed existing/new media, scaled images, external URLs, and large bundles.
- Automated diff to compare old vs new resolver decisions for the same manifest, flagging divergences before release.

### Immediate follow-ups
- Preflight/import now invokes `Dbvc\Media\Resolver::resolve_manifest()` (dry-run) alongside `DBVC_Media_Sync`; check `dbvc_backup.log` for the new “Resolver dry run completed” event and compare metrics/conflicts against legacy stats.
- Manifest schema bumped to **3**; exporter now stamps `asset_uid`, `file_hash`, `bundle_path`, and `dimensions` during manifest generation. Update import/preflight consumers to read the new keys while honoring legacy fallbacks (`original_id`, `hash`, `file_size`).
- Plan the UI changes that surface resolver `conflicts` so reviewers can upload or approve fallback actions.
- Legacy sync seeds the resolver’s `id_map` into its internal mappings and returns the resolver payload (`media_resolver`) so downstream code can skip duplicate matching; resolver-backed runs mark queue items handled before bundle/remote processing. When the resolver resolves every asset (`unresolved=0`), the legacy queue is skipped unless `dbvc_media_use_legacy_sync` filter forces a fallback.
- Admin UI and WP-CLI surface resolver metrics/conflicts so reviewers see deterministic reuse vs unresolved counts; unresolved/conflict totals now trigger warnings before apply.
- React-based admin app scaffold lives in `admin/class-admin-app.php` (asset manifest expected at `build/admin-app.*`); next steps: implement REST endpoints and build the initial React bundle (proposal list → diff detail) wiring in `media_resolver` data.

### Exporter/Preflight TODOs
- **Manifest writer** (`includes/class-backup-manager.php::build_media_index_entry`):
  1. Stamp `vf_asset_uid`/`vf_file_hash` meta (generate hash on export if missing) and include them as `asset_uid` / `file_hash`.
  2. Persist dimensions (`width`, `height`), `filesize`, and normalized `bundle_path` so resolver can verify integrity without re-reading files.
  3. Record attachment `guid` or canonical URL for diagnostic purposes; legacy `source_url` becomes fallback only.
- **Sync manifest structure**: migrate `original_id` → `asset_uid` while retaining legacy key during transition so current imports keep working; add schema bump + backward compatibility note.
- **Preflight/import** (`DBVC_Sync_Posts::import_backup` / future import pipeline):
  - Call `Resolver::resolve_manifest()` alongside `DBVC_Media_Sync::sync_manifest_media()` in dry-run mode; log the comparison.
  - Store the resulting `id_map` so diff/apply engines can reuse attachment mappings without invoking the legacy sync.
  - Surface resolver `conflicts` in the response payload so the UI can warn about missing or blocked media before apply.

---

**End of handoff.**  
This document is formatted for direct inclusion in your Codex/README or as inline developer docs in the plugin.
- Diff payload now includes `label` (human readable) and `section` keys to aid grouping in the React UI; sections map to meta/tax/media/content/etc. for collapsible panels.
- **Global resolver rules**: `/resolver-rules` (REST) and the admin UI panel now allow creating/editing rules inline in addition to deleting/exporting; CSV import/export makes it easy to seed mappings en masse.
- **Resolver rules travel with backups**: manifests now record proposal-scoped and global resolver decisions so importing on another site restores reviewer intent before media sync runs.
- **Entity detail drawer**: diff + resolver UI renders inside a modal with overlay, focus trapping, and keyboard handling so reviewers can work without scrolling the entire admin screen.
- **Advanced resolver bulk apply**: reviewers can batch decisions by conflict reason, asset UID, or manifest path, with optional “remember globally” toggle so recurring conflicts are neutralized quickly.
- **WP PHPUnit scaffold**: run `bin/install-wp-tests.sh <db> <user> <pass>` to pull the WordPress test suite, then execute `phpunit -c phpunit.xml.dist`. The bootstrap auto-detects the generated test library (or `WP_TESTS_DIR` if you prefer a custom path).
- **Entity table virtualization & search**: large proposals render via a virtualized entity table, and resolver/global rule panels include instant search inputs, dramatically reducing DOM churn during review.
- **Resolver rule form QoL**: new rules pre-fill the last attachment ID and flag duplicate IDs before submission, reducing accidental conflicts.
- **Media preview backlog**: groundwork is in place to show proposed/current thumbnails, but we need another iteration to handle differing sync paths and oversized assets before enabling it by default.
- **Review-only mode**: Configure → Import Defaults now exposes “Require DBVC Proposal review” so admins can disable the legacy Run Import form and force every change through the new React diff/resolver workflow.
