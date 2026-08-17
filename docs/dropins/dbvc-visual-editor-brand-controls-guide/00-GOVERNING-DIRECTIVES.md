# Governing Directives

These rules apply to every discovery, design, implementation, review, and release task in this package.

## 1. Adapt to the current codebase

- Treat all names and structures in this handoff as conceptual until verified.
- Inspect the latest DBVC branch, working tree, documentation, tests, and uncommitted changes before proposing edits.
- Extend current abstractions when they already solve the problem.
- Do not introduce a new service layer, state store, component system, endpoint family, naming convention, or persistence mechanism merely because it appears cleaner in isolation.
- Follow the current DBVC and Visual Editor organization, coding standards, bootstrap patterns, hook registration, capability handling, AJAX or REST conventions, asset loading, CSS conventions, and test strategy.
- When an existing pattern is imperfect but stable, make the smallest safe extension unless correcting it is required for the approved release.
- The reconciled planning checkpoint is clean, synchronized DBVC commit `5db4b40` on `codex/visual-editor-linked-posts-plan`; always recheck for newer work before editing.

## 2. Protect the repository and working tree

Before any change:

- Record the current branch.
- Run `git status --short --branch`.
- Review recent commits.
- Identify untracked and modified files.
- Do not reset, clean, stash, checkout over, amend, or otherwise alter unrelated work.
- Do not assume uncommitted Visual Editor changes are disposable.
- Keep each production release isolated enough to review and revert independently.

If unrelated changes overlap a required file, adapt carefully and document the overlap rather than replacing the file wholesale.

## 3. Search for evidence before asking or inventing

Search both repositories before deciding that a concept, catalog, provider, field list, scanner, or architecture is absent.

### DBVC repository

Search at minimum:

- `docs/`, `docs/dropins/`, `context/`, `.context/`, inventories, manifests, generated catalogs, and architecture notes
- Visual Editor bootstrap and feature settings
- Shared Globals retrieval and UI
- descriptor creation, public-token maps, hydration, sessions, and keepalive
- ACF owner, path, resolver, validation, serialization, and mutation logic
- field-family control renderers
- image, gallery, featured-image, and Media Library integrations
- object navigation and capability filtering
- journal and audit integration
- cache invalidation
- Bricks request blocking and asset enqueue logic
- current fixtures, browser tests, unit tests, and QA notes
- any existing media scanner, missing-media report, background job, transient session, or result-table implementation

### VerticalFramework repository

Use the exact evidence path:

`/Users/rhettbutler/Documents/LocalWP/frameworkflo-live/app/public/wp-content/themes/vertical`

Search at minimum:

- `acf-json/`
- ACF option-page and field-group registrations
- business information, hours, global links, logos, CTA, brand, and site-settings groups
- image/gallery fields assigned to posts, pages, CPTs, and terms
- config files, feature manifests, field catalogs, generated JSON inventories, documentation, drop-ins, context files, and repository maps
- any existing Media Health, missing-file, missing-image, attachment-integrity, or site-assurance scanner
- existing integration hooks for DBVC or the Visual Editor
- naming and category conventions that could populate registry metadata

Treat VerticalFramework as read-only evidence by default. Modify it only when an approved release requires a provider or adapter that cannot responsibly live in DBVC, and keep that work in a separately identified change scope.

## 4. Do not over-engineer

- Implement only functionality named in the current release or directly required to make it safe and production-ready.
- Avoid speculative database tables, queues, caches, APIs, adapters, UI placeholders, and abstraction layers.
- Prefer a narrow interface that can be extended later over a broad generic framework with unused options.
- Do not build full Site Assurance, broad Media Health, design tokens, bulk editing, or undo in anticipation of later programs.
- Do not add disabled navigation items for unnamed future modules.
- Do not refactor unrelated Visual Editor systems as part of feature work.

## 5. Preserve the source-authority model

The current Visual Editor safety model is non-negotiable:

- The server-side descriptor is authoritative.
- DOM markers and table findings contain lookup references, not writable field targets.
- The browser must not guess or submit an authoritative ACF key, meta key, owner, option ID, row path, or storage adapter.
- Every writable family requires an explicit resolver and mutation contract.
- Capability and nonce checks occur for every protected request.
- Shared and related-owner writes retain acknowledgement requirements.
- Nested paths preserve exact owner, parent field, row, layout, and group ancestry.
- Hidden or excluded collection values are never silently removed.
- Every committed mutation is journaled and audited using current systems.
- Bricks Builder requests remain free of Visual Editor markers and assets.

A registry record or Media Manager finding is never mutation authority. It can only be exchanged for a fresh server-resolved descriptor.

## 6. Media Manager-specific safety rules

- Never scan arbitrary meta keys.
- Determine ACF fields from current ACF definitions, applicable location rules, and proven owner/path logic.
- Default to published/live and publicly relevant entities; do not leak private or unauthorized object titles.
- A scan result represents a point-in-time observation. Recheck the source before opening and again before saving.
- A field reported empty must not be overwritten if another user or process populated it after the scan.
- Use existing WordPress Media Library workflows. Do not build a custom uploader or attachment repository.
- Assign existing local image attachments only; upload permission remains governed by WordPress.
- Clearing or replacing an assignment must not delete the attachment.
- Do not implement cross-entity `Save selected` in R1 or R2.
- Initial R2 is per-field only. A same-entity `Save Row` requires a later explicit decision plus a general media preflight/outcome/compensation contract; the current collection-specific composite path is not sufficient evidence.
- After save, rerun the targeted finding check. Do not mark a finding resolved merely because an update endpoint returned success.

## 7. Frontend-first product behavior

- New capabilities must feel native to the live frontend experience, not like a copied WordPress admin screen.
- Keep the site visible behind the workspace where current layering permits it.
- Reuse the existing toolbar, panel, popover, and movable editor conventions.
- The main editor remains the authoritative field editor unless a release explicitly approves a specialized table-row editor.
- The WordPress Media Library remains the media selection/upload surface.
- Do not add new editor/media enqueues in R1. Current Visual Editor mode already enqueues WordPress editor and media assets eagerly; changing that policy is a separately measured performance task.
- All panels must coexist safely with the admin bar, site CSS, main editor panel, and WordPress Media Library modal.

## 8. Performance is a release requirement

- Do not scan the full site during every frontend page load.
- Prefer user-triggered scans and the current repository’s existing job/session patterns.
- If no suitable scanner exists, use bounded request batches rather than one long request or a speculative background daemon.
- Do not hydrate full descriptors for every result row.
- Defer field metadata, attachment metadata, and editor controls until an entity row expands.
- Use server pagination, cursoring, or virtualization for large result sets.
- Avoid N+1 ACF field-definition, capability, permalink, and metadata queries.
- Add measurements or credible profiling for representative small and large sites before production release.

## 9. Accessibility and resilient interaction

- Use semantic controls; do not make a table row clickable without a real expansion button.
- Support keyboard navigation, visible focus, `aria-expanded`, logical focus restoration, and Escape behavior.
- Do not use color alone to communicate status.
- Preserve the existing usable target-size baseline. Additional touch/mobile-specific optimization is tabled by D-036 until explicitly reauthorized.
- Respect reduced motion where transitions are used.
- Make loading, partial, stale, empty, permission, and error states understandable without relying on visual polish.
- When the WordPress Media Library opens, suspend outside-click behavior and restore focus to the initiating control when it closes.

## 10. Static mockups are references, not runtime contracts

Claude Code will provide static HTML/CSS mockups after Codex defines the verified data, actions, and states.

- Do not copy mockup JavaScript into production.
- Do not treat mockup IDs, classes, data attributes, field keys, or sample routes as runtime contracts.
- Translate accepted visual intent into existing DBVC components and scoped styles.
- Reject mockup interactions that exceed release scope or current safety guarantees.
- Record accepted, adapted, and rejected design decisions.

## 11. Production release discipline

For every release:

1. Perform a release-specific discovery delta.
2. State the exact in-scope change before editing.
3. Implement in small reviewable slices.
4. Add automated coverage at the same time as behavior.
5. Run the repository’s established tests and static checks.
6. Complete manual/browser/accessibility QA.
7. Document compatibility, observability, feature flags, and rollback.
8. Update all tracking files.
9. Stop after the release gate; do not begin the next release automatically.

## 12. Clarification policy

Search local code, documentation, Git history, fixtures, catalogs, and the VerticalFramework checkout before asking a question. Ask only when a product choice materially changes behavior and cannot be resolved from evidence. Do not ask for class names, paths, field keys, or architecture details that are discoverable locally.
