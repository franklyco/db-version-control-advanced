# R2-G — Media Manager: UX Polish

## Production outcome

Two small, presentation-only refinements to the Frontend Media Manager, requested after the R2-F entity-media-inventory/replace work landed. Neither adds a REST route, a mutation, or a new server surface; both are client presentation and layout only.

1. **Live saved-media thumbnail** — after a user picks media and saves (assign or replace), the field's left thumbnail reflects the saved media immediately, without a page or table reload.
2. **Header-hosted status panel** — the scan status panel (state title/description/progress + action buttons) moves out of the scrolling results body and into the popup header as a compact strip, reducing crowding in the main Media Manager popup.

## Prerequisite

R2-F Slices 1–3 are complete (populated-field inventory, left-aligned lazy thumbnails, gated replace). The group-nested ACF write fix (RK-013) is in place.

## Slices

### Slice 1 — Live saved-media thumbnail (no reload)

- Before a successful save clears the staged selection, capture the picked attachment's thumbnail URL (already available client-side from `wp.media`), its alt text, and the selection count.
- After the save reconciles from the server reread, ensure the saved field's `preview` reflects the picked media: prefer the server reread `preview.url` when present (it is the source of truth on a real refresh), and fall back to the captured pick when the reread preview has no usable URL (e.g., a group-nested field or an attachment without a `thumbnail` size).
- The thumbnail then renders from `createFieldThumbnail` on the in-place panel rebuild — no list/scan request, no table reload.
- **Gate:** client presentation only; no new REST/mutation; the server reread stays the source of truth; the fallback only fills an otherwise-empty preview so the user always sees what they just picked.

### Slice 2 — Header-hosted status panel (compact)

- Relocate the `dbvc-ve-media-manager__status-panel` node (its `state-title`, `description`, `progress`, and `actions`) from `dbvc-ve-media-manager__body` into `dbvc-ve-media-manager__header`, below the identity row (`icon | heading | close`).
- Restyle it compact: smaller state title/description, tighter action buttons, a horizontal actions row. The results area (`dbvc-ve-media-manager__body`) then contains only the results table and its own empty/error states.
- The state machine is unchanged: it still targets the panel by root-scoped `data-dbvc-ve-media-manager-state-title` / `-state-description` / `-progress` and the action `data-dbvc-ve-media-manager-action` buttons, and still toggles `data-has-results` on the root — so relocating the DOM node preserves all state updates, action visibility, and ARIA labelling.
- **Gate:** DOM/CSS relocation only; no behavior change to scanning/lifecycle; the responsive floor is preserved (D-036); the panel's accessible name/labelling is retained.

## Security requirements

- No new REST route, capability surface, or mutation. Slice 1 never writes; it only chooses which already-safe preview URL to display. Slice 2 moves existing nodes and restyles them.
- The saved-thumbnail fallback only ever uses an `http(s)` URL the client already received from the native `wp.media` selection; it exposes no owner id, field key/selector, ACF object id, path, or raw stored value.

## Acceptance criteria

### Slice 1 — live thumbnail
- [x] After a successful assign, the saved field's left thumbnail shows the picked media without reload.
- [x] After a successful replace, the field's left thumbnail shows the new media without reload.
- [x] When the server reread preview has a URL, it is used; the picked fallback only fills an empty preview.
- [x] No list/scan request is issued for the thumbnail update.

### Slice 2 — header status panel
- [x] The status panel (state title/description/progress + actions) renders inside `dbvc-ve-media-manager__header`, not in the body.
- [x] The results body contains only the results table and its empty/error states.
- [x] Scan lifecycle, action-button visibility, progress updates, and ARIA labelling are unchanged.
- [x] The action buttons and labels are visibly smaller/compact; the responsive floor is preserved.

## Implementation checkpoint (2026-08-18)

Both slices implemented and verified. **Slice 1:** `reconcileAfterSave` captures the just-picked selection's thumbnail (via `savedSelectionPreview`) before clearing the staged selection and, through `applySavedPreviewFallback`, fills the reconciled field's `preview` only when the server reread preview has no URL — so the left thumbnail always reflects the saved media without a reload, while the server preview stays the source of truth. **Slice 2:** the status panel moved from `createBody` into `createHeader` (new `createStatusPanel()`), the header became a column of `__header-top` (icon/heading/close) + the compact status panel, and `media-manager.css` restyled the panel as a compact `[content | actions]` grid with smaller buttons/labels and a narrow-width stack. Verified: 2 new jsdom cases (30 total) — Slice 1 asserts the picked thumbnail (`pic-21-150x150`) appears with no list/scan call; Slice 2 asserts the panel is in the header (not the body) with the state hooks intact. The R1-D read-only invariant, media-manager lint, and a faithful static layout preview all pass; no REST/mutation/PHP surface changed (full suite unchanged at 741; agent docs 54/418/0).

## Rollback

Both slices are isolated to `media-manager-app.js` and `media-manager.css` (plus tests). Reverting those hunks restores the prior body-hosted panel and the reread-only thumbnail. No data, schema, route, or option changes are involved.
