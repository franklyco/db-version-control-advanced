# ENTITY_EDITOR_CHECKLIST.md

Working checklist for Entity Editor implementation. Mirrors the phase plan from `docs/ENTITY_EDITOR_HANDOFF.md` and is updated at the end of each phase.

---

## Phase 0 — Repo review + plan alignment (MANDATORY)
- [x] Locate sync folder configuration + resolver (file path + settings)
- [x] Confirm entity JSON format (post vs term, header keys)
- [x] Confirm exclusions for media/menus/options
- [x] Confirm DBVC engines available (import/export/logging/normalization)
- [x] Review `DBVC_ENGINE_INVENTORY.md` + other `.md` docs for hooks/filters
- [x] Identify best integration points + reuse candidates (paths + class names)
- [x] Create `ENTITY_EDITOR_REPO_REVIEW.md` with findings
- [x] Revise this handoff where assumptions conflict with reality

**Files touched**
- `ENTITY_EDITOR_REPO_REVIEW.md`
- `docs/ENTITY_EDITOR_HANDOFF.md`
- `docs/ENTITY_EDITOR_CHECKLIST.md`

**QA / verification**
- [x] Repo docs inventory reviewed with `rg --files -g '*.md'`
- [x] Handoff phase section verified against checklist source
- [x] Repo review doc exists and is committed as Phase 0 artifact

**Phase notes / risks (updated end-of-phase)**
- Phase 0 complete.
- Resolved handoff merge-conflict markers so the phased plan is clean and actionable.
- Risk: `ENTITY_EDITOR_REPO_REVIEW.md` records findings from the original snapshot and may need follow-up alignment while implementing Phase 1+.

---

## Phase 1 — Menu + routing skeleton

- [x] Add a top-level admin menu switch between **Proposal Review** and **Entity Editor**.
- [x] Add hash-based route support for `#proposal-review` and `#entity-editor`.
- [x] Keep Proposal Review as the default route when no recognized hash is present.
- [x] Render an Entity Editor skeleton surface with placeholder menu/editor panes.
- [x] Ensure Entity Editor route renders only the new skeleton surface (no CSS-only hiding of proposal review internals).
- [x] Rebuild generated admin assets after source updates.

## Notes

- This checklist was updated during implementation follow-up after initial Phase 1 delivery.

**Files touched**
- `src/admin-app/index.js`
- `build/admin-app.js`
- `build/admin-app.asset.php`
- `includes/class-entity-editor-indexer.php`
- `admin/admin-menu.php`
- `admin/admin-page.php`
- `admin/class-admin-app.php`
- `db-version-control.php`
- `docs/ENTITY_EDITOR_CHECKLIST.md`

**QA / verification**
- [x] Admin submenu renders under DBVC menu
- [ ] Unauthorized users are blocked
- [ ] Nonce validation fails closed on invalid requests

**Phase notes / risks (updated end-of-phase)**
- Added a real wp-admin submenu entry (`dbvc-entity-editor`) under DBVC.
- Submenu opens the same admin app but defaults to the Entity Editor route for implementation continuity.

---

## Phase 2 — Indexer (scan + cache + exclusions)
- [x] Implement sync folder scanner
  - [x] handle nested folders
  - [x] identify valid JSON files only
- [x] Parse minimal header info per file (avoid full parse if schema allows)
- [x] Exclude attachments/media/menus/options
- [x] Build cached index (transient + optional disk cache)
- [x] Add “Rebuild Index” action/button
- [ ] Unit: verify index builds on a large folder without timeouts (add paging or chunk scanning if needed)

**Files touched**
- `includes/class-entity-editor-indexer.php`
- `admin/class-admin-app.php`
- `db-version-control.php`
- `src/admin-app/index.js`
- `build/admin-app.js`
- `build/admin-app.asset.php`
- `docs/ENTITY_EDITOR_CHECKLIST.md`

**QA / verification**
- [x] Scanner only indexes post/term entities
- [x] Cache hit/miss behavior validated
- [x] Rebuild action refreshes stale entries

**Phase notes / risks (updated end-of-phase)**
- Phase 2 delivered with a working sync-folder indexer, REST index/rebuild endpoints, and Entity Editor route wiring in the admin app.
- Remaining sub-task: add explicit large-folder performance verification coverage (manual stress pass or dedicated test harness).

---

## Phase 3 — List table UI (filterable table)
- [x] Implement list table-style entity rows in the admin React route (Phase 3 parity with SPA architecture)
- [ ] Filters:
  - [x] entity kind (posts/terms)
  - [x] CPT list (derived from index)
  - [x] taxonomy list (derived from index)
  - [x] search
- [x] Row action: “Edit JSON”
- [x] Pagination + sortable columns (mtime, subtype, slug)
- [x] Show matched WP entity indicator if cheap to compute (or compute on demand)

**Files touched**
- `src/admin-app/index.js`
- `build/admin-app.js`
- `build/admin-app.asset.php`
- `includes/class-entity-editor-indexer.php`
- `docs/ENTITY_EDITOR_CHECKLIST.md`

**QA / verification**
- [x] Filters and search return expected rows
- [x] Pagination and sorting are stable
- [x] “Edit JSON” opens the file-focused editor panel in-route

**Phase notes / risks (updated end-of-phase)**
- Added a real wp-admin submenu entry (`dbvc-entity-editor`) under DBVC.
- Submenu opens the same admin app but defaults to the Entity Editor route for implementation continuity.

---

## Phase 4 — Editor view + Save JSON
- [x] Editor page loads file (validated path)
- [ ] Initialize CodeMirror JSON editor + linting
- [x] Add Save JSON endpoint
  - [x] parse validation
  - [x] backup creation
  - [x] atomic write
  - [x] log action
- [x] Add lock handling (transient lock) and UI feedback

**Files touched**
- `includes/class-entity-editor-indexer.php`
- `admin/class-admin-app.php`
- `src/admin-app/index.js`
- `build/admin-app.js`
- `build/admin-app.asset.php`
- `docs/ENTITY_EDITOR_CHECKLIST.md`

**QA / verification**
- [x] Invalid JSON is blocked before save
- [x] Backup file is created before write
- [x] Atomic write path succeeds and preserves file integrity
- [x] Lock is acquired on file open and required for save
- [x] Lock conflicts return actionable UI with takeover flow

**Phase notes / risks (updated end-of-phase)**
- Phase 4 started: validated file-loading endpoint and editor panel are in place.
- Save endpoint now supports JSON validation, backup-before-write, atomic replace, and logging.
- Added transient file locking with ownership metadata, save-time token validation, and forced takeover support.
- CodeMirror JSON editor + linting is the remaining Phase 4 gap before moving into Phase 5 imports.

---

## Phase 5 — Partial Import (matched fields/meta merge)
- [x] Implement matcher (UID → history → slug+subtype)
- [x] Block import if none/ambiguous matches
- [x] Implement non-destructive update:
  - [x] update core fields present in JSON
  - [x] update meta keys present in JSON
  - [x] update tax_input only for taxonomies present in JSON
- [x] Call DBVC’s canonical export/normalize pipeline after import (if applicable)
- [x] Log detailed counts

**Files touched**
- `includes/class-entity-editor-indexer.php`
- `admin/class-admin-app.php`
- `src/admin-app/index.js`
- `build/admin-app.js`
- `build/admin-app.asset.php`
- `docs/ENTITY_EDITOR_CHECKLIST.md`

**QA / verification**
- [ ] Partial import updates only provided fields/meta
- [ ] Meta keys absent from JSON are not deleted
- [ ] Ambiguous/zero matches are blocked with actionable messaging

**Phase notes / risks (updated end-of-phase)**
- Added `Save + Partial Import` endpoint + UI action with lock-token enforcement.
- Matcher precedence now follows UID/history first, then slug+subtype, with hard blocks for zero/ambiguous matches.
- Non-destructive import updates only JSON-present fields/meta/taxonomies and runs export normalization after apply.
- Risk: taxonomy export currently uses `DBVC_Sync_Taxonomies::export_selected_taxonomies()`, which may be broader than per-term export.

---

## Phase 6 — Full Replace (destructive)
- [x] Add confirmation modal + typed phrase
- [x] Implement safe deletion policy:
  - [x] protected meta allowlist (confirm with DBVC standards)
  - [x] delete meta not present in JSON (except protected)
- [x] Pre-replace snapshot:
  - [x] backup JSON file
  - [x] export current DB entity JSON snapshot
- [x] Apply replace flow (fields/meta/tax_input)
- [x] Export/normalize pipeline
- [x] Log detailed counts + references to backups

**Files touched**
- `includes/class-entity-editor-indexer.php`
- `admin/class-admin-app.php`
- `src/admin-app/index.js`
- `build/admin-app.js`
- `build/admin-app.asset.php`
- `docs/ENTITY_EDITOR_CHECKLIST.md`

**QA / verification**
- [x] Full replace requires typed confirmation phrase
- [ ] Protected keys survive deletion pass unless explicitly replaced
- [ ] Backup/snapshot artifacts are generated and traceable in logs

**Phase notes / risks (updated end-of-phase)**
- Added `Save + Full Replace` backend + UI, including typed `REPLACE` modal confirmation and lock-token validation.
- Added protected meta-key policy (filterable) and destructive deletion of non-protected keys absent from JSON.
- Added pre-replace DB snapshot artifact in `.dbvc_entity_editor_backups` plus existing JSON backup-before-write artifact.
- Risk: full replace currently does not show a preflight count preview before confirmation; it only reports final counts after apply.

---

## Phase 7 — Hardening + QA
- [ ] Verify capability + nonce checks everywhere
- [ ] Verify strict path restrictions (realpath within sync root)
- [ ] Ensure no absolute paths leak in UI
- [ ] Verify large folders performance
- [ ] Ensure “Save JSON only” never touches DB
- [ ] Verify “Partial Import” does not delete meta
- [ ] Verify “Full Replace” deletes only allowed keys and shows warning
- [x] Add tests or at least a manual QA script/checklist
- [x] Add dev docs: brief usage notes + known limitations

**Files touched**
- `docs/ENTITY_EDITOR_MANUAL_QA.md`
- `docs/ENTITY_EDITOR_USAGE.md`
- `tests/phpunit/EntityEditorEndpointsTest.php`
- `docs/ENTITY_EDITOR_CHECKLIST.md`

**QA / verification**
- [ ] Security checks pass for all actions
- [ ] Performance sanity checks pass with large sync trees
- [ ] Manual QA checklist completed and documented

**Phase notes / risks (updated end-of-phase)**
- Added a dedicated manual QA script/checklist document for security, lock, partial-import, and full-replace validation.
- Added a concise usage/limitations doc to support Phase 7 rollout and onboarding.
- Added PHPUnit coverage for Entity Editor permission/path validation and full-replace pre-snapshot behavior.

---

## Backlog — Loose Ends + Recommended Enhancements

Priority legend: `P1` critical reliability, `P2` important hardening, `P3` quality-of-life.

### Loose ends (current implementation)
- [x] `P1` Verify Entity Editor CSS is loading through the dedicated loader on real admin pages (post-fix validation after filename mismatch correction).
- [ ] `P1` Add regression test/check for asset manifest CSS discovery so future entrypoint/style renames fail fast.
- [x] `P2` Replace runtime modal/textarea DOM style mutation with class-driven styling and stable component structure.
- [x] `P2` Expand lint script coverage to include `src/admin-entity-editor/**`.
- [x] `P2` Ensure default PHPUnit suite executes Entity Editor tests without requiring explicit file path.
- [ ] `P2` Complete remaining Phase 7 security/hardening checks (capability + nonce + path restrictions + path leakage audit).
- [ ] `P2` Run large-sync-tree performance verification and document baseline timings.
- [ ] `P2` Add automated frontend behavior tests for search navigation and modal interactions (parent modal + full replace modal coexistence).
- [ ] `P3` Remove legacy `initialRoute`/hash-route remnants no longer needed after standalone Entity Editor page split.

### Recommended enhancements
- [ ] `P2` Add search mode toggles in editor (`case-sensitive`, `whole-word`) for safer JSON find operations.
- [ ] `P2` Add search result highlighting count and “jump to first/last” actions.
- [ ] `P2` Add “format JSON” action in editor (pre-save beautify with stable spacing).
- [ ] `P2` Add unsaved-changes guard before closing modal or switching files.
- [ ] `P2` Add lock heartbeat + visible countdown so stale lock timing is clear to users.
- [ ] `P2` Add preflight summary for full replace (estimated fields/meta/tax changes) before typed confirmation.
- [ ] `P3` Add side-by-side diff preview (current DB vs edited JSON) before partial/full import.
- [ ] `P3` Add optional keyboard shortcut hints (`Cmd/Ctrl+S` save JSON, `Shift+Enter` previous match).
- [ ] `P3` Add dedicated Entity Editor changelog section in docs for future release notes.
