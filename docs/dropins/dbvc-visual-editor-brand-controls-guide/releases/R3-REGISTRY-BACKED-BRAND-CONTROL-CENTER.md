# R3 — Registry-Backed Brand Control Center

## Production outcome

R3 establishes a narrow, provider-aware registry and ships a minimal Brand Control Center that lists approved global controls and opens them through the existing Visual Editor descriptor and panel system.

The release must preserve current Shared Globals behavior and should initially rely on already-proven option-owned contracts. It is not the full expanded client experience and does not add new ACF mutation families.

Current implementation baseline: `SharedGlobalFieldsController` manually creates off-render descriptors only for configured option-owned `relationship`/`post_object` fields. It is a compatibility provider precedent, not a generic registry or descriptor factory.

## Prerequisite and sequencing

R0 must be complete. Under the default product sequence, R1 and R2 should already be production-ready. R3 does not depend on Media Manager internals and must not refactor or expand that module.

## User problem

Global controls are currently discovered through a narrow relationship/post-object allowlist and are tied to a specific Shared Globals interface. DBVC needs a safe, standardized way to expose approved controls without equating “global” with arbitrary `wp_options` access.

## Primary personas

- Agency administrator
- Senior content editor
- Site administrator
- VerticalFramework integration developer

## Existing surfaces extended

- Shared Globals settings and popover
- Reserved toolbar overflow or the existing Shared Globals entry point
- Main editor/inspector panel
- Existing descriptor hydration
- Existing shared acknowledgement and journal

The actual entry point should be selected after R0. Avoid adding a new permanent toolbar button when an existing entry can evolve safely.

## In scope

### Registry foundation

- Define the smallest normalized registry contract required by R3–R6.
- Support one or more providers using existing DBVC extension conventions.
- Validate provider and control IDs.
- Normalize categories, source metadata, owner metadata, field family, scope, and status.
- Filter controls by current-user visibility and provider availability.
- Keep registry results separate from authoritative descriptors.

### Shared Globals compatibility

- Adapt current Shared Globals allowlisted option fields into registry records.
- Preserve current settings and existing relationship/post-object behavior.
- Avoid a mandatory migration if runtime adaptation is sufficient.
- Maintain existing reload, acknowledgement, mutation, journal, and cache behavior.

### Minimal Brand Control Center

- List registered controls even when they are not rendered on the current page.
- Show a minimal safe summary: label, category/group, owner/source, field family, and current status.
- Open editable controls in the existing main panel through fresh server descriptor resolution.
- Clearly represent inspect-only, unavailable, and unsupported controls.
- Use lazy descriptor hydration.

### Diagnostics

Add development/admin-observable diagnostics using current logging conventions for:

- duplicate control IDs;
- invalid provider output;
- missing ACF field definitions;
- unresolved owners;
- unsupported field families;
- provider exceptions or version mismatch.

Do not expose sensitive values in logs.

## Out of scope

- Search-heavy or fully designed expanded center UI
- All ACF option-field families
- Site Manager drawer
- Site Assurance or changes to the already-shipped Media Manager/broader Media Health scope
- Pinned controls or named workspaces
- Usage indexing or site-wide impact counts
- Preview mode
- Bulk saving, staging, or undo
- Design-token or Bricks setting writes
- New custom database tables

## Conceptual request flow

```text
Open Brand Control Center
    ↓
Request normalized registry list
    ↓
Server validates providers and filters visibility
    ↓
Render lightweight control records
    ↓
User selects a control
    ↓
Request fresh descriptor by opaque control reference
    ↓
Existing descriptor/resolver determines inspect/edit status
    ↓
Open existing main panel
    ↓
Existing save contract, acknowledgement, journal, and reload behavior
```

The opaque control reference must not contain enough client-authoritative information to target arbitrary storage.

## Implementation slices

### R3-A — Contract and validation

- Add registry/provider contract using existing code organization.
- Add normalized validation and duplicate handling.
- Add unit tests for valid, invalid, duplicate, absent, and permission-filtered providers.
- Do not render a new UI yet.

**Gate:** registry output is deterministic, filtered, and cannot introduce write authority.

### R3-B — Existing Shared Globals compatibility provider

- Convert current configured fields into registry records.
- Preserve current field identity and option owner semantics.
- Verify current relationship/post-object controls open and save without regression.
- Keep old Shared Globals path functioning.

**Gate:** no behavior regression when registry-backed discovery is disabled or unavailable.

### R3-C — Minimal center UI

- Add the smallest production interface that lists registered controls.
- Reuse existing popover, panel, loading, status, and event patterns.
- Avoid final expanded visual design; R4 will provide the richer UI.
- Add loading, empty, error, inspect-only, unsupported, and unavailable states.

**Gate:** an authorized user can discover and open a registered control that is absent from the current page.

### R3-D — Production hardening

- Verify capability filtering and nonce handling.
- Verify Bricks Builder exclusion.
- Verify R3 adds no new heavy editor/media enqueue and document the existing active-mode eager asset baseline.
- Add browser coverage and release notes.
- Document rollback and compatibility behavior.

## Data and API rules

- Prefer exact ACF field keys over field names.
- Represent the option owner using the same canonical form as current resolvers.
- Do not send arbitrary option IDs, field keys, or nested paths back from the browser as authority.
- List APIs may return a safe summary but should not return full field definitions unnecessarily.
- Full descriptors must be re-created or rehydrated server-side when a control opens.
- Registry caches, if any, must follow existing caching conventions and be invalidated by provider/settings changes.

## VerticalFramework role

R3 must work without VerticalFramework.

After the DBVC registry contract is stable, discovery may justify a small VerticalFramework provider in the same overall release. Treat it as a separate cross-repository slice with separate files, tests, and change notes. Do not directly import DBVC implementation classes across repositories unless an existing integration pattern already does so safely.

A Vertical provider may register a small proven set of existing controls, but R3 does not require cataloging every field in VerticalFramework.

## Security and stale-data requirements

- Registry membership never grants edit permission.
- Capability checks occur when listing and again when hydrating or saving.
- Shared acknowledgement remains mandatory.
- Existing stale-value checks remain unchanged.
- Missing or invalid fields fail closed.
- An unregistered option, field, or owner cannot be supplied manually.
- Sensitive operational settings must not be registered.

## Performance requirements

- Do not hydrate every full descriptor to display the list.
- Avoid per-control repeated ACF field-group or owner queries where a batch or cached lookup already exists.
- Paginate or limit providers if current evidence shows potentially large registries.
- Do not add TinyMCE, Quicktags, or Media Library enqueues solely for the center. Active Visual Editor mode already enqueues editor/media assets; optimizing that baseline is a separate measured task.

## Acceptance criteria

### Registry

- [ ] Providers can register controls through a documented DBVC extension point.
- [ ] Duplicate and malformed registrations fail predictably and are observable.
- [ ] DBVC remains fully functional when no provider is present.
- [ ] Registered controls do not become writable without an existing resolver.
- [ ] Unregistered `wp_options` values cannot be discovered or edited.

### Compatibility

- [ ] Existing Shared Globals settings continue to work.
- [ ] Existing relationship and post-object global editing has no functional regression.
- [ ] Existing acknowledgements, journal entries, cache invalidation, and reload behavior remain intact.
- [ ] Disabling the new center restores the prior surface without data loss.

### UI

- [ ] A registered control can appear when it is not rendered on the current page.
- [ ] Each row exposes source/owner context and a clear status.
- [ ] Opening a row requests a fresh authoritative descriptor.
- [ ] The existing main panel is reused.
- [ ] Loading, empty, error, inspect-only, unsupported, and unavailable states are covered.
- [ ] Keyboard and focus behavior follow current Visual Editor patterns.

### Safety and operations

- [ ] All protected requests enforce nonce and capability checks.
- [ ] No Visual Editor registry or center assets appear in Bricks Builder requests.
- [ ] No new custom table or broad data migration was introduced without proven need.
- [ ] Automated and browser tests pass.
- [ ] Release notes and rollback instructions are complete.

## Rollback

The preferred R3 rollback is feature-level:

1. Disable the registry-backed center through the existing settings/feature mechanism.
2. Retain existing Shared Globals behavior and settings.
3. Remove or disable optional providers without changing stored field values.
4. Revert code without requiring a data migration.

If discovery proves persistence is necessary, document an explicit versioned rollback before implementation.
