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
- [ ] _To be updated at phase end_

**QA / verification**
- [ ] Admin submenu renders under DBVC menu
- [ ] Unauthorized users are blocked
- [ ] Nonce validation fails closed on invalid requests

**Phase notes / risks (updated end-of-phase)**
- Pending.

---

## Phase 2 — Indexer (scan + cache + exclusions)
- [ ] Implement sync folder scanner
  - [ ] handle nested folders
  - [ ] identify valid JSON files only
- [ ] Parse minimal header info per file (avoid full parse if schema allows)
- [ ] Exclude attachments/media/menus/options
- [ ] Build cached index (transient + optional disk cache)
- [ ] Add “Rebuild Index” action/button
- [ ] Unit: verify index builds on a large folder without timeouts (add paging or chunk scanning if needed)

**Files touched**
- [ ] _To be updated at phase end_

**QA / verification**
- [ ] Scanner only indexes post/term entities
- [ ] Cache hit/miss behavior validated
- [ ] Rebuild action refreshes stale entries

**Phase notes / risks (updated end-of-phase)**
- Pending.

---

## Phase 3 — List table UI (filterable table)
- [ ] Implement `WP_List_Table` for entity rows
- [ ] Filters:
  - [ ] entity kind (posts/terms)
  - [ ] CPT list (derived from index)
  - [ ] taxonomy list (derived from index)
  - [ ] search
- [ ] Row action: “Edit JSON”
- [ ] Pagination + sortable columns (mtime, subtype, slug)
- [ ] Show matched WP entity indicator if cheap to compute (or compute on demand)

**Files touched**
- [ ] _To be updated at phase end_

**QA / verification**
- [ ] Filters and search return expected rows
- [ ] Pagination and sorting are stable
- [ ] “Edit JSON” navigates to the editor for the selected entity

**Phase notes / risks (updated end-of-phase)**
- Pending.

---

## Phase 4 — Editor view + Save JSON
- [ ] Editor page loads file (validated path)
- [ ] Initialize CodeMirror JSON editor + linting
- [ ] Add Save JSON endpoint
  - [ ] parse validation
  - [ ] backup creation
  - [ ] atomic write
  - [ ] log action
- [ ] Add lock handling (transient lock) and UI feedback

**Files touched**
- [ ] _To be updated at phase end_

**QA / verification**
- [ ] Invalid JSON is blocked before save
- [ ] Backup file is created before write
- [ ] Atomic write path succeeds and preserves file integrity

**Phase notes / risks (updated end-of-phase)**
- Pending.

---

## Phase 5 — Partial Import (matched fields/meta merge)
- [ ] Implement matcher (UID → history → slug+subtype)
- [ ] Block import if none/ambiguous matches
- [ ] Implement non-destructive update:
  - [ ] update core fields present in JSON
  - [ ] update meta keys present in JSON
  - [ ] update tax_input only for taxonomies present in JSON
- [ ] Call DBVC’s canonical export/normalize pipeline after import (if applicable)
- [ ] Log detailed counts

**Files touched**
- [ ] _To be updated at phase end_

**QA / verification**
- [ ] Partial import updates only provided fields/meta
- [ ] Meta keys absent from JSON are not deleted
- [ ] Ambiguous/zero matches are blocked with actionable messaging

**Phase notes / risks (updated end-of-phase)**
- Pending.

---

## Phase 6 — Full Replace (destructive)
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

**Files touched**
- [ ] _To be updated at phase end_

**QA / verification**
- [ ] Full replace requires typed confirmation phrase
- [ ] Protected keys survive deletion pass unless explicitly replaced
- [ ] Backup/snapshot artifacts are generated and traceable in logs

**Phase notes / risks (updated end-of-phase)**
- Pending.

---

## Phase 7 — Hardening + QA
- [ ] Verify capability + nonce checks everywhere
- [ ] Verify strict path restrictions (realpath within sync root)
- [ ] Ensure no absolute paths leak in UI
- [ ] Verify large folders performance
- [ ] Ensure “Save JSON only” never touches DB
- [ ] Verify “Partial Import” does not delete meta
- [ ] Verify “Full Replace” deletes only allowed keys and shows warning
- [ ] Add tests or at least a manual QA script/checklist
- [ ] Add dev docs: brief usage notes + known limitations

**Files touched**
- [ ] _To be updated at phase end_

**QA / verification**
- [ ] Security checks pass for all actions
- [ ] Performance sanity checks pass with large sync trees
- [ ] Manual QA checklist completed and documented

**Phase notes / risks (updated end-of-phase)**
- Pending.