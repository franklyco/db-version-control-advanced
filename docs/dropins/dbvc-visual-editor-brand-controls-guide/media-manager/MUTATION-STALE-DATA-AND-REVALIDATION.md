# Media Manager Mutation, Stale Data, and Revalidation Contract

## Core rule

Media Manager does not create a new broad mutation API. It routes each supported field through the existing Visual Editor descriptor, resolver, validation, mutation, cache, journal, and audit systems.

## Descriptor hydration

When a row expands or a field is opened:

1. Validate nonce/session and opaque scan reference.
2. Recheck current-user access to the entity.
3. Recheck live status and field applicability.
4. Resolve the field definition and exact owner/path server-side.
5. Read the current canonical value.
6. Compare it with scan-time evidence.
7. Issue/hydrate a standard descriptor only when the field remains supported.

The client cannot turn a displayed label, type, route, or scan finding into a write target.

## Media selection

### Existing attachment

Validate through the existing field contract:

- attachment exists;
- attachment is an allowed local image;
- MIME/type constraints pass;
- current user can use/select it under current policy;
- requested selection cardinality matches field family;
- gallery order is preserved.

### Upload

Use the native WordPress Media Library upload tab and core permissions/nonces.

DBVC does not directly process upload bytes in this release. An uploaded attachment becomes selectable through the same validated assignment path.

If the user lacks `upload_files`, hide or disable upload affordance while retaining selection of accessible existing media where current WordPress behavior allows.

## Stale-data policy

### Finding became non-empty

If another user/process populated the field after scan:

- block the empty-field remediation write by default;
- mark the finding `resolved elsewhere` or `changed since scan`;
- refresh/remove it after revalidation;
- never overwrite the newer value merely because the user already selected an image.

### Owner changed or disappeared

If the entity was deleted, unpublished, moved to an excluded status, or permission changed:

- fail closed;
- show an explicit row/field status;
- remove it from the active scan after refresh when appropriate.

### Field definition changed

If ACF field group/type/location/path changed:

- invalidate the field descriptor;
- do not guess a replacement field;
- mark unsupported/unavailable and require refresh.

### Gallery changed

Before replacing an empty gallery, recheck that it is still empty. If it now contains IDs, block the empty-assignment workflow and require the user to reopen the current gallery editor. Do not silently overwrite or merge without an explicit proven contract.

## Field-level save

A field save must perform:

- protected request checks;
- fresh descriptor resolution or authoritative session lookup;
- object-specific capability check;
- expected old-value/stale check;
- field-family validation and normalization;
- explicit mutation;
- cache invalidation;
- journal/audit write;
- targeted finding revalidation;
- safe UI response.

## Deferred same-entity `Save Row`

A row-level save is not part of initial R2. The current Visual Editor composite route is collection-specific and does not establish a general media batch contract.

Implement only when the current code can support:

1. fresh hydration/preflight for every dirty field before the first write;
2. independent validation for every proposed attachment set;
3. a clear parent operation/change-set identity;
4. explicit ordering;
5. truthful partial-failure reporting;
6. compensating restoration of earlier writes if an existing proven pattern supports it;
7. per-field journal items;
8. targeted revalidation for each field.

These requirements define a possible later decision gate, not an R2 implementation checklist. Ship field-level saves in R2. Do not invent a transaction abstraction solely to match the concept image.

## Cross-entity save

Not part of R1 or R2.

The interface must not submit multiple owners through a new bulk endpoint. Any `Save selected` concept remains absent until a later generalized batch and rollback program is approved.

## Journal and audit behavior

Record assignments using current Visual Editor/DBVC systems, including:

- owner and field identity in canonical server-side form;
- prior canonical value;
- new attachment ID or ordered gallery IDs;
- user and timestamp;
- scope/related/shared context where applicable;
- success/failure/rollback result.

Do not log sensitive tokens, nonces, or full opaque references.

Uploading an attachment is a WordPress media action. The DBVC assignment journal should not claim it can roll back or delete the uploaded attachment automatically.

## Cache and frontend update behavior

Use existing invalidation paths for:

- post/term metadata;
- ACF caches;
- featured-image caches;
- Bricks or page caches known to current Visual Editor;
- any provider result cache.

If the edited entity is the current frontend page and a proven marker exists, reuse current DOM patch behavior when reliable. Otherwise show a verified save state and offer/rely on the existing reload behavior.

The Media Manager table itself should update after targeted revalidation without requiring a full page reload.

## Targeted revalidation

After each successful mutation:

1. Reread the exact canonical source.
2. Confirm it is no longer empty and matches the expected field-family shape.
3. Update/remove the finding in the scan snapshot.
4. Recalculate group and summary counts.
5. Return a versioned update so stale client responses do not restore old counts.

An update call returning success is insufficient without the source reread.

## Partial failure response

A multi-field row response must identify:

- fields saved and verified;
- fields not attempted;
- fields rejected in preflight;
- fields whose write failed;
- rollback attempts and outcomes, if any;
- fields requiring manual review.

Never present a partially saved row as a complete success.

## Mutation acceptance criteria

- All writes use existing explicit field-family contracts.
- Upload uses WordPress core.
- A scan-time empty observation cannot overwrite a later non-empty value.
- Gallery contents are not silently removed or merged.
- Every committed assignment is journaled/audited.
- Cache invalidation follows current owner/family behavior.
- Findings resolve only after targeted verification.
- Partial failures are itemized.
- Disabling Media Manager leaves stored content unchanged.
