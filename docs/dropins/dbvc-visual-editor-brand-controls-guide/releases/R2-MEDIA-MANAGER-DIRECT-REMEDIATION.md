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

### R2-B — Media Library integration

- Reuse current image/gallery media-frame setup.
- Single selection for featured image/ACF image.
- Multiple ordered selection for gallery.
- Upload tab governed by WordPress capability.
- No custom upload endpoint.
- Correct focus/outside-click/Escape layering with the Media Manager panel.

This is the first slice where the currently loaded table can directly choose an existing image or use WordPress's upload tab without following the entity `Open` link. Selection remains unsaved until R2-C.

### R2-C — Field-level assignment and save

- Stage selection visibly as unsaved.
- Save through existing family contract.
- Enforce expected old empty value.
- Validate attachment IDs/MIME/cardinality.
- Journal/audit and invalidate caches.
- Targeted reread and finding update.

On success, update the expanded field, row counts, scan summary, and remove or mark a fully resolved row in place. Do not reload the browser page or require navigation to the entity URL. If a current-page rendered projection outside the Media Manager cannot be patched through an already proven Visual Editor projection, that external page content may retain the existing reload behavior; this does not permit reloading the Media Manager table itself.

**Gate:** a field populated after scan cannot be overwritten.

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
