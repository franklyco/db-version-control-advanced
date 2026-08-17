# R6 — Frontend Site Manager Workspace

## Production outcome

R6 introduces a persistent laptop/desktop frontend workspace that gives authorized users faster access to site objects and existing Visual Editor tools. It integrates the already-shipped Media Manager and Global & Brand Control Center into a coherent client-facing shell without turning DBVC into a page builder or WordPress admin replacement. Additional responsive/mobile variants are tabled by D-036 until explicitly reauthorized.

## User problem

Users must currently jump among toolbar popovers, frontend pages, and WordPress admin screens. They need a consistent way to navigate pages, posts, CPTs, terms, permitted users, and media while remaining in Visual Editor mode.

## Primary personas

- Client content editor
- Marketing manager
- Business owner
- Agency administrator

## Existing surfaces extended

- Bottom Visual Editor toolbar
- Go To Object
- Review Fields
- Status
- Global & Brand Control Center
- Frontend Media Manager
- Main editor/inspector panel
- Current-object and backend edit links
- Visual Editor exit behavior

## Product boundary

The workspace is a navigation and orchestration shell. It does not:

- edit Bricks templates or element settings;
- replace the WordPress admin;
- introduce a second field editor;
- introduce Site Assurance or additional Media Manager/Media Health expansion;
- provide batch editing;
- expose arbitrary posts, users, terms, or attachments beyond current permissions;
- create frontend routes for objects that do not have them.

## In scope

### Persistent workspace shell

- Sticky or persistent drawer on suitable desktop widths
- Open/close control integrated with the existing toolbar
- Session-persisted open/closed and selected-section state where consistent with current behavior
- Viewport clamping and compatibility with the movable main panel
- Clear current-object context

**Tabled by D-036:** smaller-screen slide-over/card variants, touch refinements, real-device optimization, and mobile-specific mockup/QA work.

### Object navigation

Provide searchable, lazy navigation for:

- Pages
- Posts
- Public or explicitly approved CPTs
- Taxonomy terms
- Users only when permission and product context justify exposure
- Media items through a safe integration with the native Media Library or existing attachment links

The exact object types should be discovered from WordPress and filtered through explicit server policy. Do not hardcode VerticalFramework CPTs into DBVC core.

### Existing tool integration

The workspace should expose or route to:

- Current object
- Review Fields
- Go To Object capabilities
- Global & Brand Control Center
- Media Manager
- Edit active object
- Exit Visual Editor

Reuse existing components and APIs. Consolidate duplicated entry points only after regression coverage proves the replacement.

### Navigation behavior

For each object:

- Open a valid permitted frontend URL when one exists.
- Preserve Visual Editor mode using the current secure mode mechanism.
- Offer a backend edit link when permitted and useful.
- Represent objects without a frontend route honestly rather than inventing one.
- Preserve current search/filter state when returning where practical.

### Laptop/desktop and accessible behavior

- Keyboard-operable drawer and navigation
- Predictable focus movement and restoration
- Escape behavior that does not conflict with the media modal or main panel
- Preserve the existing usable target-size baseline; touch-specific optimization remains tabled by D-036
- Reduced-motion respect if current project conventions support it
- No color-only status communication
- Correct landmark, dialog/drawer, navigation, and list semantics based on actual implementation

## Out of scope

- Favorites, pins, recent-object persistence beyond a minimal session list unless already available
- Named workspaces
- Site Assurance
- Broader Media Health findings beyond the existing missing-assignment Media Manager
- Brand consistency
- Bulk operations
- Cross-page live updates
- New media-management behavior beyond the already-shipped Media Manager
- User profile editing beyond existing proven Visual Editor fields
- Creating new content objects
- Deleting objects
- Reordering content

A minimal current/recent object shortcut may be included only if it directly reuses existing session behavior and does not expand scope materially.

## UI/UX mockup requirement

R6 requires a Claude Code static HTML/CSS mockup after Codex has completed the architecture and data-state contract.

The mockup must cover at least:

- desktop persistent drawer;
- collapsed/closed state;
- current object;
- object-type navigation;
- search loading, no results, error, and permission-filtered states;
- Global & Brand Control Center entry;
- Media Manager entry and current scan-status summary when available;
- interaction alongside the movable field panel;
- long labels and large result sets;
- keyboard focus order.

Medium-width/mobile variants and handset screenshots are not required while D-036 remains active.

Follow the documents under `ui-ux/`.

## Implementation slices

### R6-A — Navigation architecture and read model

- Reuse or extract the current Go To Object query behavior.
- Define object-type policy and permission filtering.
- Define frontend/backend route behavior.
- Add pagination and bounded search.
- Avoid loading all objects at page load.
- Add test fixtures for mixed post types, terms, users, statuses, and attachments.

### R6-B — Workspace state and integration contract

- Define how the drawer coexists with toolbar popovers and the main panel.
- Define focus and Escape behavior.
- Define session persistence using existing storage patterns.
- Define navigation-mode preservation.
- Produce a state/action contract for mockup creation.

### R6-C — Claude Code mockup and design review

- Produce static mockup deliverables.
- Compare against current Visual Editor visual language and CSS constraints.
- Resolve conflicts between mockup behavior and runtime architecture.
- Record accepted/rejected visual decisions.

### R6-D — Production shell

- Implement the drawer using existing component/event conventions.
- Integrate current object and object navigation.
- Integrate Review Fields, Media Manager, and Global & Brand Control Center entry points.
- Keep the existing main panel authoritative for edits.
- Scope CSS and prevent site/Bricks leakage.

### R6-E — Laptop/desktop accessibility and production hardening

- Supported laptop/desktop, keyboard, and screen-reader review
- Large-site performance and pagination
- Permission boundary tests
- Builder exclusion tests
- Fallback to existing toolbar/popovers
- Release notes and rollback plan

Tablet/mobile/touch-specific and real-device hardening remains tabled by D-036.

## Object navigation rules

### Posts, pages, and CPTs

- Filter by current-user readability and product policy.
- Distinguish published, draft, private, and other statuses using current conventions.
- Do not expose private titles to users who cannot read them.
- Prefer frontend open for public objects and backend edit when permitted.

### Terms

- Show only taxonomies and terms appropriate to the current user and site.
- Use a valid public term URL only when available.
- Provide backend edit links only with permission.
- Do not imply every term has a public archive.

### Users

- Treat user enumeration as sensitive.
- Include users only when current capabilities and actual Visual Editor use cases justify it.
- Prefer role/capability policy already present in the plugin.
- Do not expose private email addresses or profile fields in list summaries.
- An author archive or frontend route is not guaranteed.

### Media

- Reuse the native Media Library where possible.
- Do not build a second asset repository.
- Attachment records must be permission-filtered.
- Opening a media item does not itself create a field mutation target.
- Keep assignment of media within existing image/gallery field controls.

## Mode and routing requirements

- Preserve the current nonce/cookie mode mechanism.
- Do not append insecure raw field or owner targeting to navigation URLs.
- Ensure navigation does not activate Visual Editor inside Bricks Builder.
- Handle redirects, canonical URLs, and inaccessible frontend objects without trapping the user.
- When Visual Editor mode cannot be maintained safely, show an explicit status and provide a safe route back.

## Performance requirements

- No full site object inventory at initial page load.
- Search and lists are paginated or cursor-based according to current API conventions.
- Avoid N+1 capability and permalink lookups.
- Cache stable object-type metadata where the current plugin safely does so.
- Cancel or ignore stale search responses.
- Preserve list scroll and query state during passive status updates.
- Large result sets must not freeze the frontend.

## Acceptance criteria

### Workspace shell

- [ ] Authorized users can open and close the workspace from the current Visual Editor UI.
- [ ] Supported laptop/desktop behavior is usable.
- [ ] **Tabled by D-036:** distinct small-screen behavior.
- [ ] Drawer state does not conflict with the movable main panel or WordPress Media Library.
- [ ] Focus enters, traverses, and returns predictably.
- [ ] Bricks Builder requests remain unaffected.

### Navigation

- [ ] Pages, posts, approved CPTs, and terms are searchable without preloading the entire site.
- [ ] User and media visibility follow explicit permission policy.
- [ ] Frontend and backend links are shown only when valid and permitted.
- [ ] Visual Editor mode is preserved through supported frontend navigation.
- [ ] Objects without frontend routes are represented accurately.
- [ ] Search loading, empty, error, and pagination states are covered.

### Tool integration

- [ ] Review Fields remains functional.
- [ ] Global & Brand Control Center opens from the workspace.
- [ ] Media Manager opens from the workspace without duplicating its scan or mutation logic.
- [ ] Go To Object functionality is reused or retired only after equivalent behavior is verified.
- [ ] The existing main panel remains the only field editing surface.
- [ ] Exit Visual Editor remains explicit and reliable.

### Compatibility and rollback

- [ ] Existing toolbar/popover paths remain available as a fallback during rollout.
- [ ] Disabling the workspace restores prior navigation behavior.
- [ ] No stored content or object data is migrated merely to support the workspace.
- [ ] Existing Visual Editor save, journal, acknowledgement, and descriptor behavior has no regression.

### Production quality

- [ ] Supported desktop-browser, keyboard, accessibility, and large-site performance QA pass.
- [ ] **Tabled by D-036:** touch/mobile-specific and real-device QA.
- [ ] Static mockup decisions are documented and correctly translated into production components.
- [ ] CSS does not leak into the site or Bricks Builder.
- [ ] Release notes and rollback instructions are complete.
