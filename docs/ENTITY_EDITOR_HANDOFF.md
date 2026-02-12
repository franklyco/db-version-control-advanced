# ENTITY_EDITOR_HANDOFF.md
**Project:** DBVC Plugin → New Admin Feature: **Entity Editor**  
**Owner:** Flourish / DBVC  
**Date:** 2026-02-12  
**Status:** Implementation handoff for Codex (VS Code)

---

## 0) Executive summary

Add a new DBVC admin submenu called **Entity Editor** that lets admins:

1) **Browse** the current site’s DBVC **sync folder entities** (Posts + Terms only) in a filterable table (filter by CPT or Taxonomy).  
2) **Open** the corresponding JSON file for an entity in an **editable JSON editor** (CodeMirror).  
3) Perform one of three operations:

- **A. Save Only JSON in Sync Folder**  
  Validates JSON and writes the file (atomic write + backup). Does **not** modify WP DB.

- **B. Update/Import only Matched Fields/Meta** *(non-destructive merge)*  
  Imports only fields/meta present in editor JSON into the matched WP entity. Does **not** delete anything else.

- **C. Update/Import Entire Entity** *(destructive replace)*  
  Requires a confirmation prompt; removes existing meta (per policy/allowlist) and replaces entity to match JSON exactly.

The feature must be secure, avoid collisions with existing DBVC engines, and integrate with current DBVC hooks/filters (see `DBVC_ENGINE_INVENTORY.md` and other recently added docs).

---

## 1) Non-goals / exclusions (explicit)

- Do **not** include: media/attachments, menus/nav menu items, options/settings entities.
- Do **not** attempt to build a generic JSON schema editor. This is specifically for DBVC entity JSON files.
- Do **not** auto-import on every edit. Import occurs only when user triggers an import action.
- Do **not** change existing DBVC import/export behavior globally; keep changes scoped to this feature.

---

## 2) Assumptions (to be confirmed during repo review)

These assumptions are **placeholders** until Codex completes the required repo review:

- DBVC stores entity JSON files in a configured “sync folder” path (single root, with subfolders by entity type).
- DBVC has existing import/export engines and logging helpers.
- DBVC uses the term **Entities** to refer to posts/terms.
- DBVC has an established pattern for:
  - registering admin menus
  - capabilities checks
  - AJAX/REST handlers with nonces
  - storing “entity uid” (e.g. `vf_object_uid`, `dbvc_object_uid`, etc.)

If any assumption is wrong, **update this handoff** and proceed with the corrected integration points.

---

## 3) Required first step: Deep repo review (Codex must do this before coding)

### 3.1 What Codex must locate and summarize
Codex must scan the local DBVC plugin repo and produce a short internal report (in a new markdown doc or in chat) covering:

1. **How DBVC determines the sync folder path** (settings/options/constants).
2. **Where entity JSON files live** (directory layout, naming conventions).
3. **How DBVC identifies entity type** inside JSON (header keys/fields).
4. Existing engines for:
   - Entity import
   - Entity export
   - Drift detection (if any)
   - Field/meta normalization
   - Logging
5. Existing admin patterns:
   - menu registration
   - page rendering pattern
   - list tables if present
   - JS/CSS enqueue conventions
6. Existing security helpers:
   - nonce generators/verifiers
   - capability gates
   - file path sanitization helpers
7. What unique IDs DBVC uses to match entity JSON ↔ WP entity (UID fields, history meta, etc.).
8. Review **`DBVC_ENGINE_INVENTORY.md`** and any other recently added `.md` docs for hooks/filters and engine integration guidance.

### 3.2 Repo review deliverables (must be produced before implementation)
- A short file: `ENTITY_EDITOR_REPO_REVIEW.md` containing:
  - confirmed integration points
  - any deviations from assumptions
  - list of existing functions/classes to reuse (with file paths)
  - proposed placement for new files/classes
  - risks/collisions and mitigation

Then Codex should **revise this handoff plan** minimally (only where needed) to reflect the discovered reality.

---

## 4) Admin UX / pages

### 4.1 New submenu
Add a submenu under DBVC top-level menu:

- **Menu title:** Entity Editor
- **Page slug:** `dbvc-entity-editor` (confirm naming convention)
- **Capability:** use DBVC’s existing cap (or `manage_options` if DBVC uses that) — confirm via repo review.

### 4.2 Views
**List View**
- Shows an index of sync-folder entities (posts + terms only)
- Filters:
  - Entity Kind: Posts / Terms (tabs or dropdown)
  - Post Type (only when Posts selected)
  - Taxonomy (only when Terms selected)
  - Search (title/name, slug, UID, filename)
- Row actions:
  - **Edit JSON**

**Editor View**
- Shows CodeMirror JSON editor
- Shows read-only meta panel:
  - file path (relative)
  - last modified
  - detected entity kind + subtype
  - UID (if present)
  - matched WP entity (link to edit) if found
- Actions:
  - **Save JSON**
  - **Save + Partial Import**
  - **Save + Full Replace** (confirmation required)

---

## 5) Core behaviors & rules

### 5.1 Save Only JSON (file write)
**Must:**
- Validate JSON parses
- Create backup before write
- Write atomically:
  - write to temp
  - rename over original
- Prevent path traversal:
  - only allow files inside sync folder
- Log action via DBVC logger

**Should:**
- Normalize JSON formatting (optional) using DBVC’s canonical JSON encoder if one exists.
- Record editor user + timestamp in an audit log.

### 5.2 Partial Import (Matched Fields/Meta) — non-destructive
**Intent:** Update only what is present in editor JSON; do not delete anything else in WP.

**Required constraints:**
- Must match exactly one WP entity (post/term). If none or ambiguous → block.
- Update only fields present in JSON:
  - Posts: core fields (title/content/excerpt/status/etc) only when present
  - Terms: name/description/slug only when present
- Meta: For each meta key present in JSON → update meta. Do not delete any meta keys absent from JSON.
- Taxonomies/term relationships (if JSON includes `tax_input`):
  - Only update taxonomies that appear in JSON.
  - Default behavior: replace the set for those taxonomies OR merge — decide after repo review; prefer DBVC’s established behavior to avoid drift/collisions.

**After import:**
- Trigger DBVC’s normal export/normalize pipeline (if DBVC already does this on entity updates) so JSON remains canonical.
- Log detailed counts: updated meta keys, updated core fields, term changes, etc.

### 5.3 Full Replace (Entire Entity) — destructive
**Intent:** Make WP entity match JSON exactly, removing existing meta not present in JSON.

**Required UX:**
- Confirmation modal + typed phrase (e.g. `REPLACE`)
- Display:
  - entity identifier
  - number of meta keys to be removed
  - “Cannot be easily undone” warning
- Optional checkbox: “I understand this will delete meta not present in JSON.”

**Deletion policy (important):**
Deleting *all* meta can break WP internals and plugins. Implement a safe policy:
- Preserve an allowlist of protected keys unless JSON explicitly includes them.
- Examples (confirm and adjust):
  - `_edit_lock`, `_edit_last`
  - `_wp_old_slug`
  - `_wp_page_template` (pages)
  - `_thumbnail_id` (optional; since media excluded, consider preserving by default)

**Full replace flow:**
1. Snapshot current entity state (export current JSON to backups folder).
2. Apply core fields from JSON.
3. Delete meta keys per policy.
4. Add meta from JSON.
5. Apply tax relationships (tax_input) according to DBVC standard.
6. Export/normalize via DBVC pipeline (recommended).
7. Log operation + counts + backup pointers.

---

## 6) Indexing strategy (performance)

Do not parse the entire sync folder on every request in production sites with many entities.

**Approach:**
- Build an index cache:
  - transient + optional on-disk cache file (e.g. `.dbvc-entity-index.json` at sync root)
- Index entries per file:
  - entity_kind: `post` | `term`
  - subtype: post_type | taxonomy
  - title/name, slug
  - UID (if present)
  - filepath (relative)
  - file mtime
  - matched wp_id (optional; can be computed lazily)
  - hash/version fields if DBVC already stores them

**Index rebuild triggers:**
- Manual “Rebuild Index” button
- TTL expiry
- Sync folder signature changes (e.g. store last scan timestamp + filecount + latest mtime)

**Exclusion filter:**
- skip JSON entities identified as:
  - attachments/media
  - menus/nav menu items
  - options
- Determine by JSON “type” markers and/or folder location patterns (from repo review).

---

## 7) Matching logic (JSON ↔ WP entity)

Order of precedence (confirm DBVC standards):
1. **DBVC UID** field (strongest)
2. **DBVC history mapping** meta fields (if exists)
3. **slug + subtype**
4. Avoid title/name match unless DBVC already supports it with clear disambiguation UI

If multiple matches found:
- Show a blocking error with candidates and require user action (or a selection UI) before import.

---

## 8) Security requirements (non-negotiable)

- Capability checks for every page and every action endpoint.
- Nonce checks for every POST.
- Strict file path validation:
  - resolve to realpath
  - ensure file is within sync folder realpath
  - disallow `..`, symlinks escaping, etc.
- Rate-limit or lock edits (see locking below) to avoid concurrent write corruption.
- Sanitize/escape all output in admin screens.
- Never expose absolute server paths in UI (use relative paths).

---

## 9) Editor implementation details

### 9.1 CodeMirror
Use core WP CodeMirror:
- `wp_enqueue_code_editor(['type' => 'application/json'])`
- Initialize editor for textarea
- Block Save/Import buttons if JSON invalid
- Show lint errors inline

### 9.2 Endpoints
Prefer using DBVC’s established request pattern:
- Admin-AJAX actions or REST routes (choose based on existing conventions)

Needed actions:
- Load file contents
- Save file
- Save + partial import
- Save + full replace

Each endpoint must:
- check cap + nonce
- validate file path within sync folder
- validate JSON on save/import

---

## 10) Backups, logging, and locking

### 10.1 Backups
Create backups before any write/import:
- Folder: `.dbvc_entity_editor_backups/` (under sync folder or DBVC private folder; confirm best location)
- Name: `{filename}.{timestamp}.bak.json`
- For destructive replace: also export current DB entity JSON snapshot to backups folder

### 10.2 Logging
Use DBVC logging engine:
- Record:
  - user_id
  - timestamp
  - operation type
  - file identifier/relative path
  - matched wp_id
  - counts (meta updated/deleted, terms changed, fields updated)
  - success/failure + message

### 10.3 Locking
Prevent two admins from saving simultaneously:
- transient lock keyed by file hash or relative path
- Show lock owner + time
- allow override when stale (e.g. > 15 minutes)

---

## 11) Proposed file/class layout (adjust after repo review)

> IMPORTANT: Use DBVC’s established autoloading/naming conventions.

Suggested module folder:
- `includes/entity-editor/`
  - `class-dbvc-entity-editor-menu.php`
  - `class-dbvc-entity-editor-controller.php`
  - `class-dbvc-entity-indexer.php`
  - `class-dbvc-entity-list-table.php`
  - `class-dbvc-entity-matcher.php`
  - `class-dbvc-entity-importer.php`
  - `assets/entity-editor.js`
  - `assets/entity-editor.css`

Add minimal bootstrapping in the DBVC main loader:
- Include module only in admin
- Lazy-load assets only on Entity Editor pages

---

## 12) Collision avoidance guidelines

- Prefix everything with DBVC’s namespace/prefix (e.g. `DBVC_` or `dbvc_`).
- Avoid global hooks that run on every admin page.
- Avoid overriding existing DBVC import/export behavior; call into engines rather than duplicating.
- Keep all new filters/actions uniquely named: `dbvc_entity_editor_*`.
- Reuse DBVC utilities for:
  - path resolution
  - JSON encoding/decoding
  - logging
  - entity matching
  - import/export pipelines

---

## 13) Phased implementation checklist (with sub-tasks)

### Phase 0 — Repo review + plan alignment (MANDATORY)
- [ ] Locate sync folder configuration + resolver (file path + settings)
- [ ] Confirm entity JSON format (post vs term, header keys)
- [ ] Confirm exclusions for media/menus/options
- [ ] Confirm DBVC engines available (import/export/logging/normalization)
- [ ] Review `DBVC_ENGINE_INVENTORY.md` + other `.md` docs for hooks/filters
- [ ] Identify best integration points + reuse candidates (paths + class names)
- [ ] Create `ENTITY_EDITOR_REPO_REVIEW.md` with findings
- [ ] Revise this handoff where assumptions conflict with reality

### Phase 1 — Menu + routing skeleton
- [ ] Register submenu “Entity Editor” under DBVC
- [ ] Create controller with two screens:
  - [ ] list view route
  - [ ] editor view route (expects file param / entity id)
- [ ] Add capability gate + nonces
- [ ] Add page-specific enqueue for scripts/styles

### Phase 2 — Indexer (scan + cache + exclusions)
- [ ] Implement sync folder scanner
  - [ ] handle nested folders
  - [ ] identify valid JSON files only
- [ ] Parse minimal header info per file (avoid full parse if schema allows)
- [ ] Exclude attachments/media/menus/options
- [ ] Build cached index (transient + optional disk cache)
- [ ] Add “Rebuild Index” action/button
- [ ] Unit: verify index builds on a large folder without timeouts (add paging or chunk scanning if needed)

### Phase 3 — List table UI (filterable table)
- [ ] Implement `WP_List_Table` for entity rows
- [ ] Filters:
  - [ ] entity kind (posts/terms)
  - [ ] CPT list (derived from index)
  - [ ] taxonomy list (derived from index)
  - [ ] search
- [ ] Row action: “Edit JSON”
- [ ] Pagination + sortable columns (mtime, subtype, slug)
- [ ] Show matched WP entity indicator if cheap to compute (or compute on demand)

### Phase 4 — Editor view + Save JSON
- [ ] Editor page loads file (validated path)
- [ ] Initialize CodeMirror JSON editor + linting
- [ ] Add Save JSON endpoint
  - [ ] parse validation
  - [ ] backup creation
  - [ ] atomic write
  - [ ] log action
- [ ] Add lock handling (transient lock) and UI feedback

### Phase 5 — Partial Import (matched fields/meta merge)
- [ ] Implement matcher (UID → history → slug+subtype)
- [ ] Block import if none/ambiguous matches
- [ ] Implement non-destructive update:
  - [ ] update core fields present in JSON
  - [ ] update meta keys present in JSON
  - [ ] update tax_input only for taxonomies present in JSON
- [ ] Call DBVC’s canonical export/normalize pipeline after import (if applicable)
- [ ] Log detailed counts

### Phase 6 — Full Replace (destructive)
- [ ] Add confirmation modal + typed phrase
- [ ] Implement safe deletion policy:
  - [ ] protected meta allowlist (confirm with DBVC standards)
  - [ ] delete meta not present in JSON (except protected)
- [ ] Pre-replace snapshot:
  - [ ] backup JSON file
  - [ ] export current DB entity JSON snapshot
- [ ] Apply replace flow (fields/meta/tax_input)
- [ ] Export/normalize pipeline
- [ ] Log detailed counts + references to backups

### Phase 7 — Hardening + QA
- [ ] Verify capability + nonce checks everywhere
- [ ] Verify strict path restrictions (realpath within sync root)
- [ ] Ensure no absolute paths leak in UI
- [ ] Verify large folders performance
- [ ] Ensure “Save JSON only” never touches DB
- [ ] Verify “Partial Import” does not delete meta
- [ ] Verify “Full Replace” deletes only allowed keys and shows warning
- [ ] Add tests or at least a manual QA script/checklist
- [ ] Add dev docs: brief usage notes + known limitations

---

## 14) QA / manual test checklist

- [ ] Table lists only posts/terms entities
- [ ] Filters and search behave correctly
- [ ] Edit JSON loads correct file, shows correct subtype/kind
- [ ] Invalid JSON blocks saving/import and shows lint errors
- [ ] Save JSON creates backup + writes atomically
- [ ] Partial import updates only keys present; does not delete any other meta
- [ ] Full replace prompts confirmation; deletes meta per policy; matches JSON afterward
- [ ] Logs produced for each action with correct counts
- [ ] Locking prevents concurrent writes

---

## 15) Open decisions (Codex to resolve during repo review)

1. Should action endpoints be REST or admin-ajax? (Follow DBVC conventions)
2. What is DBVC’s canonical JSON formatting/normalization approach?
3. What is the preferred “UID” key(s) and match order?
4. What meta keys are protected in DBVC (allowlist) for full replace?
5. Where is the safest backup folder location per DBVC security guidance?

---

## 16) Codex notes

- **Must** read `DBVC_ENGINE_INVENTORY.md` and other `.md` docs first.
- Prefer **reuse** of existing import/export/match/logging functions over re-implementing.
- Keep the feature self-contained and lazy-loaded to avoid performance regression across wp-admin.

