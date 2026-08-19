# R2 — Frontend Media Manager Direct Remediation

## Production outcome

R2 makes R1 findings actionable. Authorized users can expand an entity, choose or upload images through the native WordPress Media Library, save supported featured-image/ACF image/ACF gallery assignments, and see findings resolve after server revalidation.

R2 reuses current Visual Editor field contracts. It does not introduce arbitrary metadata writes or cross-entity bulk saving.

## Prerequisite

R1 must be production-ready with proven scan accuracy, permissions, performance, and row hydration.

## In scope

- Fresh standard descriptor hydration for each current finding
- Native WordPress Media Library integration
- Existing local image attachment validation
- Featured-image assignment
- ACF image assignment
- ACF gallery replacement/assignment
- Unsaved preview state inside expanded rows or routing to the existing main panel
- Field-level save
- Stale/conflict handling
- Journal/audit integration
- Cache invalidation
- Targeted finding revalidation
- Counter/row updates without a full Media Manager page/table reload for supported successful field saves
- Current-page DOM patch or existing reload behavior when reliable

## Out of scope

- Cross-entity `Save selected`
- Same-entity `Save Row` in the initial release
- One attachment applied to many fields
- Custom uploader or attachment deletion
- ACF file/oEmbed/video fields
- Static Bricks images/backgrounds
- Generalized transaction/undo system
- Repeater/flexible structural changes
- Editing non-empty fields from this missing-assignment workflow unless current UX explicitly routes to the normal editor

## Interaction decision

R0/R1 discovery should choose the smallest safe production UI:

### Preferred when current controls are reusable inline

Expanded rows contain compact image/gallery controls and draft previews with field-level save.

### Preferred when embedding would duplicate editor logic

Expanded rows show each finding and an `Open Editor` action that opens the existing main panel in image/gallery mode. The user remains in Media Manager and does not navigate to another page.

Do not fork image/gallery editor logic solely to match the concept image.

## Implementation slices

### R2-A — Descriptor bridge

- Exchange opaque finding references for fresh current descriptors.
- Recheck entity status, capability, field applicability, and empty state.
- Return writable/inspect-only/changed/unavailable status.
- Add tests for stale scan references and changed field definitions.

**Gate:** no client-supplied field target can bypass normal descriptor authority.

**R2-A checkpoint (2026-08-16):** implemented `src/MediaManager/MediaFindingDescriptorBridge.php` plus a protected `POST .../scans/{scan_ref}/groups/{group_ref}/findings/{finding_ref}/descriptor` route. The bridge loads the user/site-bound snapshot, validates generation/revision, resolves the owner/field only from the snapshot group/finding, rechecks owner eligibility/status/capability (via `EligibilityPolicy` and `CapabilityManager::canEditDescriptor`), rescans the single owner to reconfirm field applicability and the current empty value by fingerprint, and only then mints one fresh standard `EditableDescriptor` for the correct family — `post_featured_image`, `acf_image`, or `acf_gallery` — routing to exactly one existing resolver. The descriptor is persisted in a fresh user-bound session through the new narrow `EditableRegistry::persistDetachedDescriptor()`, and the response returns only opaque token/session identifiers plus safe labels and status (writable/changed/resolved/unavailable); it never exposes owner ids, field keys/names/selectors, ACF object ids, paths, or the empty fingerprint. It opens no Media Library frame, hydrates no value, and mutates nothing. Focused coverage passes 11 tests/200 assertions, including the three writable families, server-resolved selector/group-path carriage, no-raw-target projection, user-bound session isolation, tampered/malformed refs, stale generation/revision, expired snapshot, field populated after scan (`resolved`), changed empty evidence (`changed`), and unpublished/deleted owners (`unavailable`). Combined R1-A-R1-E plus R2-A passes 34 tests/1,327 assertions; agent docs pass 54 records/416 surfaces/0 unmapped after registering the new route. Media Library selection, upload, staged selection, and any save remain R2-B/R2-C.

### R2-B — Media Library integration

- Reuse current image/gallery media-frame setup.
- Single selection for featured image/ACF image.
- Multiple ordered selection for gallery.
- Upload tab governed by WordPress capability.
- No custom upload endpoint.
- Correct focus/outside-click/Escape layering with the Media Manager panel.

This is the first slice where the currently loaded table can directly choose an existing image or use WordPress's upload tab without following the entity `Open` link. Selection remains unsaved until R2-C.

**R2-B checkpoint (2026-08-16):** implemented in `media-manager-app.js` (a capability-gated `assign-media` control per still-`missing` field, `openAssignFrame`, staged-selection state, and a targeted `refreshExpansionPanel`), `api-client.js` (`mediaManager.descriptor`), `AssetLoader.php` (localized R2-B strings), and `media-manager.css`. Activating the control calls the R2-A bridge and, on a `writable` descriptor, opens the native `wp.media` frame — `multiple:false` for featured/ACF image, `multiple:true` for ACF gallery — reusing the same standard config as `overlay-app.js`'s image/gallery builders (D-046; the overlay was left untouched). The upload tab appears only when WordPress grants `upload_files`. Non-`writable` descriptors (`changed`/`resolved`/`unavailable`) surface a status notice and never open the frame. The selection is staged client-side with an `Unsaved selection` badge, thumbnail preview, `Replace`/`Clear selection`, and a polite live announcement; collapse, re-expansion, and clearing discard it. Escape and modal layering reuse the existing `mediaModalIsOpen` guard. No save, mutation, expected-empty check, journal write, cache invalidation, or reconciliation occurs, and the descriptor token/session and raw targets never enter the DOM. jsdom passes 16 tests (5 new R2-B); targeted lint is clean; the R1-D read-only invariant was updated to allow staged, unsaved `wp.media`; the full suite is 718/8,467 with the same six inherited failures; agent docs are 54/416/0. Real-browser `wp.media` open/upload/focus-layering QA is the residual R2-B gate under the accepted authenticated-runtime limit.

### R2-C — Field-level assignment and save

- Stage selection visibly as unsaved.
- Save through existing family contract.
- Enforce expected old empty value.
- Validate attachment IDs/MIME/cardinality.
- Journal/audit and invalidate caches.
- Targeted reread and finding update.

On success, update the expanded field, row counts, scan summary, and remove or mark a fully resolved row in place. Do not reload the browser page or require navigation to the entity URL. If a current-page rendered projection outside the Media Manager cannot be patched through an already proven Visual Editor projection, that external page content may retain the existing reload behavior; this does not permit reloading the Media Manager table itself.

**Gate:** a field populated after scan cannot be overwritten.

**R2-C checkpoint (2026-08-16):** implemented `src/MediaManager/MediaAssignmentService.php`, a protected `POST .../scans/{scan_ref}/groups/{group_ref}/findings/{finding_ref}/assignment` route, the extracted `MediaFindingDescriptorBridge::resolveFinding()` shared by R2-A and R2-C, and the frontend save/reconcile in `media-manager-app.js` (`saveAssignment`/`reconcileAfterSave`) plus `api-client.js` `mediaManager.assign`. On save the service re-runs the full R2-A revalidation as the expected-empty precondition — a field populated after the scan is blocked with `409 media_assignment_stale` and never overwritten, and the write target is always the freshly re-minted descriptor resolved only from the snapshot. The value is validated for cardinality and, through the resolver, for attachment MIME/type; the write, journal/audit, and cache invalidation run through the shared `MutationService`; the group is reread with `expandGroup` and the client reconciles the expanded field, the row's missing count, and the scan summary, marking a fully resolved row in place with no list/scan reload. Focused coverage passes 7 PHP tests/81 assertions (three-family save + reconcile, expected-empty block, non-image/empty rejection, stale-generation block) and 3 jsdom cases (no-reload reconciliation, save-conflict retains selection, saving state); the R1-D read-only invariant was updated to allow the gated save through the dedicated endpoint only; the full suite is 725/8,550 with the same six inherited failures; agent docs are 54/417/0. Real-browser save/upload QA is the residual R2-C gate under the accepted authenticated-runtime limit.

### R2-D — UX states and Claude refinement

Use the R1 mockup as a base and add verified states for:

- media modal open;
- image selected but unsaved;
- gallery selected but unsaved;
- upload unavailable;
- save in progress;
- saved/verified;
- changed since scan;
- validation error;
- entity resolved and removed.

### R2-E — Production hardening

- Security, stale, attachment, and permission tests
- Journal/cache verification
- Media Library browser/keyboard QA at supported laptop/desktop viewports; touch/mobile-specific QA is tabled by D-036
- Performance with repeated row expansion
- Current-page DOM/reload QA
- Feature isolation, release notes, and rollback

## Security requirements

- Every protected request enforces nonce/session and object-specific capability.
- Media Manager base access does not grant upload permission.
- Selected attachment IDs are validated server-side.
- Only supported local image attachments are accepted.
- The browser cannot select a different owner/field by modifying markup.
- Shared/related acknowledgement behavior is retained if a future eligible owner requires it; initial post/term scope normally uses current owner semantics.

## Stale and conflict behavior

- If a field is no longer empty, block the remediation write and refresh the finding.
- If a gallery gained content, do not replace or merge automatically.
- If owner status/permission changed, fail closed.
- If descriptor session expired, rehydrate from the finding reference rather than trusting the old client payload.
- If a selected attachment was deleted before save, reject and retain clear error state.

## Acceptance criteria

### Media selection

- [ ] Featured-image and ACF image findings open single-image media selection.
- [ ] ACF gallery findings open the existing ordered multi-image workflow.
- [ ] Upload is available only when WordPress permits it.
- [ ] Media Manager remains open behind the core modal and focus restores correctly.
- [ ] Selected media is visibly unsaved until save completes.

### Mutation

- [ ] Every save uses a current descriptor and existing field-family contract.
- [ ] Expected-old-value/stale checks run immediately before write.
- [ ] Attachment IDs and image MIME/type are validated.
- [ ] Clearing/replacing assignments never deletes attachments.
- [ ] Every successful assignment is journaled/audited.
- [ ] Relevant caches are invalidated.

### Revalidation and UI

- [ ] A successful save is followed by a canonical source reread.
- [ ] Resolved field and entity counts update accurately.
- [ ] Fully resolved rows are removed or clearly marked without losing table context.
- [ ] Stale/resolved-elsewhere findings are never overwritten.

### Scope

- [ ] Cross-entity bulk save is absent.
- [ ] Same-entity `Save Row` is absent from the initial release.
- [ ] No generic meta or custom upload endpoint exists.
- [ ] Existing image/gallery behavior for normal field markers has no regression.
- [ ] Disabling R2 leaves R1 read-only scan/report available if separately gated.

## Rollback

Preferred rollback is feature-level:

1. Disable direct remediation while leaving R1 scanning available.
2. Existing assignments remain valid WordPress/ACF content.
3. Users can continue editing through the current Visual Editor/main panel or WordPress backend.
4. Revert code with no schema migration.

A content revert is separate from feature rollback and must use current journal/recovery capabilities; R2 does not promise true generalized undo.
