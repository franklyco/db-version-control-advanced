# R2-F — Media Manager: Entity Media Inventory & Replace

## Production outcome

R2-F extends the Frontend Media Manager from "find and fill missing media" into managing an entity's supported media fields. When a user expands an entity, the detail panel lists **all** supported media fields — both empty and already-populated — each with a left-aligned square thumbnail preview, and lets an authorized user **replace** an existing value (single image or ordered gallery), not only fill an empty one.

R2-F reuses the R2-A descriptor bridge, the R2-B `wp.media` frame, and the R2-C audited mutation pipeline. It introduces no arbitrary metadata writes, no attachment deletion, and no cross-entity bulk save.

**Status (2026-08-17): all three slices delivered.** Slice 1 (populated-field inventory + bounded preview), Slice 2 (left-aligned lazy thumbnail presentation), and Slice 3 (gated replace mutation with the expected-current-value precondition) are implemented and verified. See the per-slice checkpoints below.

## Authorized scope change (supersedes two R2 exclusions)

R2 explicitly excluded "editing non-empty fields from this missing-assignment workflow" and deferred "same-entity Save Row". The maintainer authorized disabling the **"no editing non-empty fields" exclusion gate** on 2026-08-16 (decision D-050) for R2-F and future Media Manager phases. R2-F therefore permits a **controlled overwrite** of a populated field, governed by an expected-current-value precondition rather than the R2-C expected-empty gate. Overwriting still never deletes attachments and still routes through the shared audited mutation pipeline.

## Prerequisite

R2-A through R2-D implemented and R2-C's field-level save proven (journal/cache verified in R2-E1).

## Confirmed architectural decisions

- **D1 — Report populated fields.** The detail panel (on expand) resolves the entity's full supported-media inventory: empty fields (the existing findings) plus populated fields. The top-level results table and its counts remain "entities with missing media" — the snapshot stays empty-focused; the inventory is resolved live on expand (where the owner is already rescanned).
- **D2 — Bounded preview.** Populated fields expose a sanitized `preview` — thumbnail URL, alt text, and (for galleries) an attachment count — never the field key/name/selector, owner id, ACF object id, path, or raw stored value. This is media the user already has access to; the opaque-target invariant holds for everything else.
- **D3 — Replace safety = expected-current-value.** Replacing a populated field cannot use the R2-C "must still be empty" gate. The client sends the value it read (the current attachment id(s)); the server re-resolves the field, and rejects the write if the field changed since it was read (optimistic concurrency). No attachment is deleted; the overwrite is journaled/audited and the caches invalidated.

## In scope

- Live entity media inventory in the detail panel: empty + populated supported fields (featured image, ACF image, ACF gallery).
- Left-aligned, square, rounded, full-field-height thumbnail preview per field, lazy-loaded.
- Accent-color placeholder for empty fields (until a default placeholder image is supplied) and for gallery/array fields (single placeholder until a dynamic mini-grid is planned).
- Replace (single image / ordered gallery) for populated fields, with the expected-current-value precondition, journal/audit, and cache invalidation.

## Out of scope (unchanged from R2 unless noted)

- Attachment deletion or a custom uploader.
- Cross-entity `Save selected` and same-entity multi-field `Save Row`.
- ACF file/oEmbed/video fields; static Bricks images/backgrounds.
- Repeater/flexible structural changes.
- A dynamic gallery mini-grid (deferred; single placeholder/thumbnail for now).
- Reporting populated fields in the top-level results table or its counts (the inventory is detail-panel-only).

## Slices

### Slice 1 — Populated-field inventory + bounded preview (read-only)

- Extend `MediaScanService` to resolve populated (`assigned`) supported media fields alongside the empty findings when inventory is requested, each with a sanitized `preview` (thumbnail URL, alt, attachment count).
- `MediaScanReadModel::expandGroup` projects the full inventory: the existing empty findings (`missing`/`changed`/`resolved_or_changed`/`unavailable`) plus populated fields (`assigned` with `preview`), and a `populated` count. The top-level list is unchanged.
- The frontend normalizes and lists populated fields (status label only; no thumbnail rendering or replace control yet).
- **Gate:** the inventory exposes no raw target beyond the sanitized preview; the top-level table/counts are unchanged; no mutation path is added.

**Slice 1 checkpoint (2026-08-16):** implemented. `MediaScanService::scan()` gains an `$include_assigned` mode that also resolves populated fields with a sanitized `preview` (`buildPreview` → thumbnail URL/alt/count); `MediaScanReadModel::expandGroup` rescans in inventory mode, projects populated fields as `status: "assigned"` via `projectAssignedField`, merges the preview onto a resolved (empty-at-scan, now populated) finding, dedups so each field appears once, and adds a `populated` count. The frontend normalizes `assigned` fields and their preview and renders a `Has media` status chip with no assign/replace control. The snapshot and top-level list stay empty-focused. Verified: `VisualEditorMediaManagerR2FTest` 3 tests/29 assertions (inventory + preview + no-leak + unchanged top-level list + gallery count) and 1 jsdom case (24 total); the R1-D read-only invariant is intact. Slice 2 (thumbnail presentation) and Slice 3 (replace mutation) remain.

### Slice 2 — Thumbnail presentation

- Restructure `.dbvc-ve-media-manager__field-item` into a horizontal `[thumbnail | content]` layout: thumbnail left, square, rounded, full field-item height.
- Populated field → the attachment thumbnail (`loading="lazy"`, `decoding="async"`). Empty field → the same wrap with an accent-color background placeholder (single swap point for a future default image). Gallery/array → a single placeholder/thumbnail.
- Thumbnails render only inside the already-lazy detail panel; nothing is fetched for collapsed rows.
- **Gate:** no eager image loading; the responsive floor is preserved; no mobile-specific work (D-036).

**Slice 2 checkpoint (2026-08-17):** implemented. `createFieldProjection` now renders each field as `[thumbnail | content]`; `createFieldThumbnail` prefers a staged selection thumbnail, then the populated `preview.url`, then an accent-color placeholder wrap for empty fields, with a `+{count}` badge for galleries. Images set `loading="lazy"`/`decoding="async"` via attributes and render only inside the already-lazy detail panel. CSS adds the flex `field-item`/`field-content`/`field-thumb` layout (square `aspect-ratio: 1/1`, rounded, full-height, `.is-placeholder` accent via `color-mix`). Verified: jsdom `R2-F Slice 2` case (thumb/content cells, lazy attributes, placeholder, gallery `+2` badge); the media-manager lint set is clean; the R1-D read-only invariant is intact. Slice 3 remains.

### Slice 3 — Replace mutation (gated; the deliberate overwrite crossing)

- A "Replace image" / "Replace selection" control for populated fields, reusing the R2-B `wp.media` frame, staged unsaved.
- Save through a replace contract that enforces the **expected-current-value** precondition immediately before the write (fails closed if the field changed since it was read), validates attachment IDs/MIME/cardinality, journals/audits, invalidates caches, and reconciles the field/preview without a table reload. Never deletes attachments.
- **Gate:** a field changed since it was read cannot be silently overwritten; the overwrite is journaled; no attachment is deleted; the write target is always the freshly server-resolved descriptor.

**Slice 3 checkpoint (2026-08-17):** implemented. `MediaScanService` now emits an opaque `value_fingerprint` (`vemv_[a-f0-9]{24}`) for each populated field; `MediaScanReadModel::projectAssignedField` exposes it as `valueRef` (only for replaceable families) and flips `availableActions.replace` accordingly. `MediaFindingDescriptorBridge::resolveReplaceable` re-resolves the owner from the snapshot, rescans in inventory mode, confirms the field is still populated, and enforces the expected-current-value precondition by `hash_equals` on the fingerprint — every non-writable outcome is a hard `WP_Error` (`media_replace_stale`, `media_replace_not_populated`, `media_replace_value_ref_invalid`, `media_replace_unavailable`). `MediaAssignmentService::replace` shares one `applyMutation` pipeline with `assign` (validate → normalize → `MutationService::mutate` → journal/audit/cache → `expandGroup` reconcile) and returns `status: "replaced"`; the resolver overwrites the field reference only and never deletes attachments. A dedicated `POST …/findings/{finding_ref}/replacement` route carries `expectedValueRef`. The frontend adds `createFieldReplaceControls`, `beginReplaceMedia` (no descriptor pre-call; the endpoint revalidates at save), and `saveReplacement` (client `replace()` with the opaque ref). Verified: `VisualEditorMediaManagerR2FReplaceTest` 6 tests/62 assertions (success + fresh fingerprint + no attachment deletion + cache event; stale-ref block; emptied-after-read block; malformed-ref reject; non-image reject; gallery overwrite) and 3 jsdom cases (control gating on `valueRef`; expected-current-value POST + reconcile; stale 409 retains the staged selection). Full PHP suite 738 tests with only the six inherited failures; media-manager lint clean; agent-docs strict coverage passes with the new route mapped; the R1-D read-only invariant is extended to assert the distinct gated replace endpoint and still forbids direct `fetch(`/`.save(`/composite-save.

### Slice 4 — Post-assign replaceability (planned; reconcile projects a newly-populated finding as `assigned`)

**Problem (field report, 2026-08-18):** after assigning media to a field that was *missing* at scan time (e.g. the featured image on the "Agencies" CPT), the save reconcile projects the field as `resolved_or_changed` with the message *"This field is no longer confirmed missing. Refresh the scan before taking further action."* — a terminal state left over from R2-C, before replace existed. `expandGroup` merges the new preview onto it **but assigns no `valueRef`**, so the replace control (which requires status `assigned` + a `valueRef`) never renders, and the assign control (which requires `missing`) is also gone. The just-assigned field lands in a gap: no assign control, no replace control, and a "refresh the scan" dead end. This affects **every** just-assigned family (featured/acf_image/acf_gallery), not only featured images — it was surfaced by the single-field "Agencies" post because the user went to replace immediately after assigning. It is **not** an R2-E regression; it is a latent R2-C/R2-F interaction ([`MediaScanReadModel::expandGroup`](../../../../addons/visual-editor/src/MediaManager/MediaScanReadModel.php) lines ~151–178).

**Fix:** in `expandGroup`, when an original finding is **now populated** — absent from the current *missing* findings **and** present in the live *assigned* inventory (`assigned_by_ref[finding_ref]`) — project it as an **`assigned`** field via `projectAssignedField` (which already emits `valueRef`, the sanitized preview, and `availableActions.replace = true`) instead of `resolved_or_changed`. The genuinely *gone/ineligible* case (populated inventory absent — field definition removed, owner ineligible, etc.) stays `resolved_or_changed` with the refresh prompt. The field's `missing` count is unchanged (still 0), so the row is still marked done (the "resolved/done" marking is driven by the missing count, not the row status); the row status shifts from `resolved_or_changed` to `current` for a fully-resolved row. The newly-assigned field's `valueRef` comes from the same live rescan as any other assigned field, so replace's expected-current-value precondition (Slice 3) is unaffected and continues to fail closed on a concurrent change.

- **Gate:** read-model projection only — no new REST route, no mutation, no capability change. The `resolved_or_changed`/refresh path is retained for the genuinely gone/ineligible case. A field made replaceable by this projection is subject to exactly the same Slice 3 replace precondition as any populated field.

**Slice 4 checkpoint (2026-08-18):** implemented. `MediaScanReadModel::expandGroup` now projects an original finding that is now populated (absent from the current missing findings and present in `assigned_by_ref`) as an **`assigned`** field via `projectAssignedField` (valueRef + `replace: true` + preview), incrementing `populated` instead of `resolvedOrChanged`; the genuinely gone/ineligible case still falls through to `resolved_or_changed`. Verified: `VisualEditorMediaManagerR2CTest` now asserts a just-assigned featured/ACF-image field reconciles as `assigned` with a `vemv_` valueRef and `replace: true`; `VisualEditorMediaManagerR1CTest` asserts a now-populated field counts as `populated` (not `resolvedOrChanged`) with a valueRef; the R2-C jsdom save case asserts the reconciled field is `assigned` and immediately offers a `replace-media` control (no rescan) while the row is still marked resolved (missing count 0). Read-model projection only — no new REST/mutation surface. Full PHP suite 751 with the same six inherited failures; media-manager lint clean; jsdom 34/34; agent docs 54/418/0.

## Security requirements

- Every protected request enforces nonce/session, active-mode, and object-specific capability; replace additionally re-resolves the target server-side and applies the expected-current-value precondition.
- Preview URLs are the sanitized thumbnail of an accessible attachment; no field key/selector/owner id/ACF object id/path/raw value is exposed.
- Selected/replacement attachment IDs are validated server-side; only supported local image attachments are accepted; clearing/replacing never deletes attachments.
- The browser cannot select a different owner/field by modifying markup.

## Stale and conflict behavior

- If a populated field changed since it was read (different current value), block the replace and refresh the finding.
- If the field became empty since it was read, route to the normal assign path rather than replace.
- If owner status/permission changed, fail closed.
- If a selected attachment was deleted before save, reject and retain a clear error state.

## Acceptance criteria

### Inventory & preview
- [x] Expanding an entity lists both empty and populated supported media fields.
- [x] Populated fields expose only a sanitized preview (thumbnail URL, alt, count).
- [x] The top-level results table and counts are unchanged.
- [x] Thumbnails are lazy-loaded and never fetched for collapsed rows.

### Replace
- [x] Populated image/gallery fields offer a replace control.
- [x] Every replace uses a freshly server-resolved descriptor and the existing family contract.
- [x] The expected-current-value precondition runs immediately before the write; a field changed since it was read is not overwritten.
- [x] Attachment IDs and image MIME/type/cardinality are validated; replacing never deletes attachments.
- [x] Every replace is journaled/audited and relevant caches invalidated.
- [x] A successful replace rereads and reconciles the field/preview without a table reload.

### Post-assign replaceability (Slice 4)
- [x] After assigning a previously-missing featured/acf_image/acf_gallery field, the field projects as `assigned` with a `valueRef` and a replace control, without a rescan.
- [x] The "refresh the scan" message no longer shows for a just-assigned field.
- [x] A genuinely removed/ineligible field still projects as `resolved_or_changed` with the refresh prompt.
- [x] The row is still marked done (missing count 0); the top-level table behavior is unchanged.
- [x] No new REST/mutation surface; replace on the just-assigned field is subject to the same Slice 3 precondition.

## Rollback

Preferred rollback is feature-level and non-destructive: disable the Media Manager (default-off) to remove the whole surface; existing assignments remain valid WordPress/ACF content; revert code with no schema migration. A content revert of a replaced value uses the change journal/recovery capabilities; R2-F does not promise generalized undo.
