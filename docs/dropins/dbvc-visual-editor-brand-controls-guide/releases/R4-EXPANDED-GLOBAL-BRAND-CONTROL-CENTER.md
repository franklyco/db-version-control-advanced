# R4 — Expanded Global & Brand Control Center

## Production outcome

R4 turns the minimal R3 center into a coherent client-facing workspace for approved global and brand controls. It improves organization, discoverability, source clarity, and status handling while continuing to use only field families and mutation contracts proven at that point.

R4 is primarily a UI, read-model, and workflow release. It must not quietly broaden mutation authority.

## User problem

Users should not need to know which ACF option page, field group, backend screen, or Bricks binding owns a global value. The R3 center proves the registry; R4 makes it practical for routine client use.

## Primary personas

- Client content editor
- Marketing manager
- Business owner
- Agency administrator

## Existing surfaces extended

- R3 Brand Control Center
- Shared Globals
- Main editor/inspector panel
- Toolbar and existing popover shell
- Status messaging

## In scope

### Client-facing organization

- Category navigation based on registered metadata
- Search across labels, descriptions, safe owner labels, and approved keywords
- Filtering by category, field family, status, and scope where useful
- Grouping by option page or ACF field group when that metadata is proven
- Stable sorting with provider-defined order and deterministic fallbacks

Initial visible categories should be driven by actual registered controls. Typical categories may include:

- Brand Identity
- Business Identity
- Reusable Content
- Other Globals

Do not show empty future categories.

### Control records

Each displayed control should provide an appropriately compact representation of:

- label;
- short description when supplied;
- category/group;
- owner/source label;
- scope;
- field/control family;
- safe current-value summary;
- editable, inspect-only, unsupported, unavailable, or restricted state;
- action to open the existing panel.

Long text, WYSIWYG, gallery, and connected values require type-specific summaries rather than full content dumps.

### Unrendered controls

Registered controls must remain discoverable even when they do not appear on the current frontend page. Do not imply current-page usage when none has been observed.

### Shared Globals integration

- Decide from evidence whether Shared Globals becomes a compatibility route, subsection, alias, or legacy fallback.
- Preserve current settings and URLs/actions where possible.
- Do not remove the old surface until the replacement has passed production QA.

### UI state and accessibility

Implement first-class states for:

- initial loading;
- category/search loading;
- no controls registered;
- no search matches;
- provider error;
- unavailable source;
- unsupported family;
- inspect-only source;
- permission-filtered result;
- descriptor-loading when opening a control;
- save/reload status through the existing panel.

## Out of scope

- Enabling unsupported ACF option families; that is R5
- Pinned controls, named workspaces, or completion tracking
- Site-wide usage counts or impact indexing
- Temporary preview
- Batch editing or Save All across controls
- Site Manager drawer
- Site Assurance, changes to the already-shipped Media Manager, or design-system controls
- Arbitrary user-defined categories or a new category-management UI

## UI/UX mockup requirement

R4 requires a static HTML/CSS reference from Claude Code before production markup and styling are finalized.

Codex must first produce:

1. a verified data/state contract;
2. the actual list of actions and permissions;
3. the current Visual Editor component and CSS constraints;
4. required laptop/desktop layout and accessibility behavior; additional responsive/mobile behavior remains tabled by D-036;
5. representative sample controls using non-sensitive fixture data.

Then follow `ui-ux/CLAUDE-CODE-MOCKUP-HANDOFF.md` and `ui-ux/MOCKUP-TO-PRODUCTION-INTEGRATION.md`.

The mockup is a visual and interaction reference. It is not production DOM authority and must not dictate insecure data attributes or a parallel component architecture.

## Implementation slices

### R4-A — Read model and query behavior

- Add server-supported search/filter parameters only as needed.
- Produce type-specific safe summaries.
- Keep full descriptor hydration lazy.
- Add deterministic grouping and sorting.
- Cover provider errors without breaking the entire center.

#### R4-A checkpoint — 2026-08-30 (Slice landed)

Backend shape shipped end-to-end; frontend integration is R4-C. Summary of
what stands in `main`:

- `ControlRecord` widened with two optional public properties: `description`
  (`sanitize_text_field`) and `sortKey` (`sanitize_key`). Both default to
  empty; both are round-tripped through `fromArray()` and emitted on the
  safe `toListItem()` projection. Interior tests in
  `tests/phpunit/VisualEditorControlCenterR4ATest.php` pin the projection.
- `ControlProvider` interface widened with `buildValueSummary(ControlRecord,
  sessionId): ?array`. All four in-tree implementations
  (`SharedGlobalsControlProvider`, `VF_Vertical_Control_Provider`, plus the
  three anon-class test factories) supply `return null;` defaults; the
  Shared Globals implementation is real for `relationship` + `post_object`
  families.
- `ControlRegistry`:
  - `listControls()` accepts `family` (`sanitize_key`) and `q` (trimmed,
    case-insensitive, matched against label OR description) in addition to
    the existing `category` + `status`.
  - Records now sort globally by `sortKey ASC → label ASC (case-insensitive)
    → publicId ASC` (previously per-provider then `id` ASC — the R3-A test
    order still holds because sortKey is empty in those tests).
  - `getControls()` per provider is wrapped in try/catch; a throwing
    provider is captured in `getProviderErrors()` (`{providerId → {message}}`)
    and its records are dropped without shielding the rest of the map. The
    R3-A observer channel (`dbvc_visual_editor_control_registry_invalid`)
    also fires with the new `provider_threw` reason.
  - `buildValueSummaryForRecord(record, sessionId)` mirrors
    `buildDescriptorForRecord`.
- `SharedGlobalsControlProvider`:
  - Emits `description` sourced from (in order) the
    `dbvc_visual_editor_control_center_description` filter (Vertical hooks
    this to inject `vf_field_context_get_entry_primary_purpose()`), then
    ACF's own `instructions`, then empty.
  - Emits `sortKey` as `shared_{fieldName}` so Shared Globals sits ahead of
    `vertical_*` records under the registry's ascending sort.
  - `buildValueSummary()` returns a `{family, count, firstTitles (≤3),
    hasMore}` shape for `relationship` + `post_object` families. Rechecks
    the option-owned capability probe (the same one visibility uses) before
    reading; returns `null` on empty values / gated capability / structural
    mismatch. A fourth optional constructor seam `$optionValueResolver`
    fronts `get_field($name, 'option', false)` for the summary path (test
    injection) — R3-B call sites and the existing R3-B tests keep working
    unchanged.
- `ControlCenterListController` response bumped `viewModelVersion` from 1
  → 2. `query` echoes all four params (`category`, `status`, `family`,
  `q`). New `providerErrors` map surfaces alongside `items`. `q` is trimmed
  and clamped at 128 characters at the controller.
- New `ControlCenterValueSummariesController` route:
  `POST .../session/{id}/control-center/value-summaries` body
  `{publicIds: string[]}` (batch cap **50**, over-cap ⇒ 400). Returns
  `{ok, summaries: {publicId → summary|null}}`. Per record: resolves the
  visible record → mints a descriptor via the provider → rechecks
  capability → asks the provider for its summary. Any step failing collapses
  that entry to `null` (fail-soft — the drawer renders nothing in that
  slot). Wired under the R3-D two-part kill switch in `Rest\Routes`.
- Vertical:
  - `VF_Vertical_Control_Provider` maps `description` via
    `vf_field_context_get_entry_primary_purpose($fieldName)` → curation
    `notes` → empty, and `sortKey` as `vertical_{1|2|3|9}_{fieldName}`
    keyed on `client_priority` (`must=1, should=2, nice=3, empty=9`).
    `buildValueSummary` still returns `null` (Vertical records are all
    `status="unsupported"` in the MVP).
  - `functions/features/dbvc-visual-editor/dbvc-visual-editor.php` adds a
    filter callback on `dbvc_visual_editor_control_center_description` that
    injects the same Field Context primary-purpose lookup into DBVC Shared
    Globals rows.

Test coverage: `VisualEditorControlCenterR4ATest` adds 19 focused cases
(sort behavior, filter widening, provider-error capture, SharedGlobals
description/sortKey/summary, new list-controller contract, batch endpoint
happy path + cap + dedup + edit-mode gate). Existing R3-A/B/C-1/D suites
stay green with only one localized adjustment: the routes test now asserts
`viewModelVersion=2` + four-key `query` echo + empty `providerErrors`.

Baselines after the slice: PHPUnit 888 tests / 9305 assertions (19 new;
same 7 pre-existing failures across unrelated Bricks / Content Collector /
Content Migration / Proposal Diff / Capability Landscape suites, which
match the pre-R4-A baseline).

### R4-B — UI contract and Claude Code mockup

- Document screens, states, data, actions, and accessibility.
- Generate static mockup artifacts.
- Review mockup against actual runtime constraints.
- Record accepted and rejected mockup decisions.

### R4-C — Production UI integration

- Reuse current Visual Editor shell, panel, focus, keyboard, and event systems.
- Scope styles to avoid site and Bricks leakage.
- Implement search, categories, filters, summaries, and status states.
- Keep control opening routed through the current panel.

### R4-D — Shared Globals transition and hardening

- Add compatibility entry or fallback.
- Verify existing relationship/post-object flows.
- Test large registries and long labels/values.
- Complete supported laptop/desktop and accessibility QA. Additional responsive/mobile and touch-specific QA remains tabled by D-036.

## Interaction model

A recommended interaction pattern is:

```text
Toolbar entry
    ↓
Global & Brand Control Center
    ├── Search
    ├── Category navigation
    ├── Optional status/family filters
    └── Control list
            ↓ Open
      Existing main editor panel
            ↓ Save / Save and Reload
      Existing journal and status systems
```

Do not create inline editing inside list rows in R4. Keeping edits in the existing main panel reduces duplicated validation, media-modal handling, WYSIWYG behavior, and save-state complexity.

## Data rules

- Search must operate on approved metadata, not raw arbitrary option values.
- Value summaries must be escaped and type-aware.
- Media summaries should use attachment IDs and safe metadata resolved server-side.
- Connected-item summaries should not return full object data unnecessarily.
- Restricted controls should follow current visibility conventions.
- Provider/category failures should not expose implementation details to clients.

## Performance requirements

- Search/filter requests must be debounced or submitted intentionally using current UI conventions.
- Avoid reloading the complete registry when only client-side filtering of an already-small result is appropriate.
- Use server pagination when evidence shows the list can be large.
- Keep descriptors and connected-item search lazy. Add no new TinyMCE/Media Library enqueue; current active Visual Editor mode already carries those assets.
- Reuse cached ACF field-group and option-page metadata where safe.

## Acceptance criteria

### Discoverability

- [ ] Users can search registered controls by approved labels and descriptions.
- [ ] Controls are grouped into meaningful non-empty categories.
- [ ] Option-page or field-group metadata is shown only when proven.
- [ ] Controls absent from the current page remain discoverable.
- [ ] No arbitrary option or field becomes searchable.

### Clarity

- [ ] Every control shows authoritative source/owner context.
- [ ] Editable, inspect-only, unsupported, unavailable, and loading states are distinguishable without relying on color alone.
- [ ] Safe value summaries are appropriate to each supported family.
- [ ] Current-page presence is not confused with site-wide usage.

### Interaction

- [ ] Selecting a control opens the existing main panel.
- [ ] Focus moves predictably and returns appropriately when the panel closes.
- [ ] Search, category, filter, and scroll state behave consistently during passive status updates.
- [ ] Touch and narrow-screen behavior are usable.
- [ ] Media Library and WordPress editor interactions remain unaffected.

### Compatibility and safety

- [ ] Existing Shared Globals users retain a functional path.
- [ ] R4 adds no new mutation authority.
- [ ] Capability, nonce, descriptor, acknowledgement, stale-value, journal, and audit behavior are unchanged.
- [ ] Bricks Builder remains unaffected.
- [ ] The center can be disabled without changing stored content.

### Mockup integration

- [ ] Static mockup deliverables are stored or referenced in the repository.
- [ ] Accepted visual decisions are mapped to existing components.
- [ ] Mockup-only markup, fake data targeting, or global CSS was not copied blindly.
- [ ] Accessibility and runtime states omitted by the mockup were added in production.
