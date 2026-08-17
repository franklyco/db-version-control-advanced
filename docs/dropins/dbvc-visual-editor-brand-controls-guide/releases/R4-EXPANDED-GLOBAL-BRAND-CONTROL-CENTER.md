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
