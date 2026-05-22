# DBVC Visual Editor Toolbar 2.0 Implementation Guide

## Goal

Replace the current bottom-corner `dbvc-ve-statusbar` presentation with a primary bottom-center Visual Editor toolbar that can host status, field review, object navigation, and shared/global collection management without weakening descriptor ownership or save-contract safety.

This is a runtime UX shell and launcher project. It must not change resolver classification, descriptor source-of-truth rules, mutation contracts, or editability decisions by itself.

## Visual Direction

The toolbar should follow the attached reference direction:

- fixed bottom-center dock
- dark, low-height, rounded central pill
- circular satellite buttons on either side
- icon-first controls with accessible labels and tooltips
- upward-opening sub-panels anchored to toolbar actions
- restrained shadows and borders
- no large instructional text in the page UI

The toolbar should feel like an editor control surface, not a modal footer. It should stay compact while still making the main Visual Editor affordances discoverable.

## Current Baseline

Existing frontend status UI:

- `ensureStatusBar()` creates `.dbvc-ve-statusbar`
- `updateStatusBar()` owns active mode title, marker count, current/active entity edit link, and save/session messages
- field index state lives in:
  - `state.fieldIndexOpen`
  - `state.fieldIndexFilter`
  - `state.fieldIndexOpenSubgroups`
  - `state.fieldIndexOpenItems`
- `renderStatusBarMeta()` renders marker count, `Review fields`, filters, grouped field index rows, `Locate`, and `Open`
- statusbar click actions use `data-dbvc-ve-statusbar-action`
- `.dbvc-ve-panel` remains the actual field editor and collection editor
- connected-items search currently depends on descriptor-scoped `ReferenceSearchController`

Existing code paths likely involved:

- `assets/js/overlay-app.js`
- `assets/css/overlay.css`
- `src/Assets/AssetLoader.php`
- `src/Rest/Routes.php`
- new REST controllers only if object/global search requires server data not already present

Existing docs to keep aligned:

- `DBVC_VISUAL_EDITOR_FIELD_INDEX_PLAN.md`
- `DBVC_VISUAL_EDITOR_COLLECTION_EDITOR_PLAN.md`
- `DBVC_VISUAL_EDITOR_BADGE_AND_HYDRATION_PLAN.md`
- `DBVC_VISUAL_EDITOR_PHASES.md`
- `CHANGELOG.md`

## Product Scope

Toolbar 2.0 should include these first-class actions:

1. Visual Editor status and field review
2. Go to Object
3. Shared Globals
4. Current/active object edit link
5. Session and mode controls
6. Overflow menu for secondary actions

Do not add bulk field editing, row lifecycle mutation, flexible layout lifecycle mutation, or generalized relationship mutation as part of the toolbar shell.

## Toolbar Anatomy

Recommended DOM shape:

```html
<div class="dbvc-ve-toolbar" data-state="active">
  <button class="dbvc-ve-toolbar__button" data-dbvc-ve-toolbar-action="toggle-status"></button>
  <button class="dbvc-ve-toolbar__button" data-dbvc-ve-toolbar-action="go-object"></button>
  <div class="dbvc-ve-toolbar__dock">
    <button data-dbvc-ve-toolbar-action="review-fields"></button>
    <button data-dbvc-ve-toolbar-action="shared-globals"></button>
    <button data-dbvc-ve-toolbar-action="active-source"></button>
    <button data-dbvc-ve-toolbar-action="display-options"></button>
    <button data-dbvc-ve-toolbar-action="overflow"></button>
  </div>
  <a class="dbvc-ve-toolbar__button" data-dbvc-ve-toolbar-action="edit-object"></a>
  <button class="dbvc-ve-toolbar__button" data-dbvc-ve-toolbar-action="toggle-mode"></button>
  <div class="dbvc-ve-toolbar-popover" hidden></div>
</div>
```

Exact button order can change after live use, but the first implementation should keep status/field review, object navigation, and shared globals visible without requiring the overflow menu.

Icon guidance:

- use icon-only buttons with `aria-label`, `title`, and a tooltip
- use a small local SVG icon helper or existing WordPress-safe icons; do not add a build dependency only for this toolbar
- keep button dimensions fixed so labels, counts, and hover states do not shift layout
- text labels belong inside upward sub-panels, not inside toolbar buttons

## Statusbar Migration

The statusbar is already more than a status line because it contains the field index. Move it safely in two steps.

### Step 1: Compatibility Wrapper

Keep the existing statusbar state and renderer intact, but mount it inside a new toolbar popover.

Requirements:

- keep `updateStatusBar()` as the only writer for status count, message, and active edit link during the first migration
- preserve field index grouping, filters, open subgroup/item state, and `Locate`/`Open`
- preserve `data-dbvc-ve-statusbar-action` handling until toolbar actions are stable
- keep old `.dbvc-ve-statusbar` CSS selectors aliased or scoped so existing statusbar behavior can be restored quickly
- avoid moving descriptor hydration into toolbar boot

Acceptance:

- current marker count still updates
- session-expired messages still show
- save success/error messages still show
- field index opens upward from the toolbar
- `Locate` and `Open` behave exactly as before
- active entity edit link still switches from current page owner to active field owner when the panel opens

### Step 2: Native Toolbar Status Module

After compatibility is stable, extract statusbar-specific rendering into toolbar modules:

- status chip
- field review popover
- active owner link
- session message strip

This can retire the `.dbvc-ve-statusbar` root only after the field index and save/session message behavior are fully covered by toolbar tests.

## Upward Popover Model

All toolbar sub-panels should use one shared popover manager:

- one open popover at a time
- anchored above the clicked toolbar button or central dock
- fixed positioning, clamped inside the viewport
- max-height based on available space above toolbar
- independent internal scrolling
- `Escape` closes the popover
- outside click closes the popover
- clicks inside WordPress Media Library modals must not close the Visual Editor panel or toolbar popover
- opening a field editor panel should close nonessential toolbar popovers unless the popover is the field review launcher

Popover state should be separate from descriptor state. Closing a popover must not clear cached descriptors, selected markers, or panel save state.

## Go To Object

### Source Shape

Go to Object is a navigation tool, not an editor.

It should let authorized Visual Editor users search for editable or inspectable destination objects and navigate to their frontend page or backend edit screen.

Initial searchable object types:

- public post types where the current user can edit at least one matching object
- public taxonomies where the current user can edit matching terms
- optional current page context shortcuts
- optional recent objects from the current session or current field index

Deferred:

- users
- ACF option pages
- private object types without explicit allowlist
- arbitrary URL navigation

### Data Contract

Add a dedicated REST endpoint only if existing bootstrap data is not enough:

- route example: `GET /dbvc/v1/visual-editor/object-search`
- requires active Visual Editor mode
- requires `canUseVisualEditor()`
- sanitizes `search`, `type`, `subtype`, `limit`
- caps results to a small limit, such as 20
- returns only public navigation metadata:
  - `objectType`
  - `id`
  - `title`
  - `typeLabel`
  - `status`
  - `frontendUrl`
  - `backendUrl`
  - `canEdit`

Do not return ACF field values, descriptor payloads, nonces beyond the normal REST nonce, or mutable save contracts.

### UI Behavior

The popover should open upward from the toolbar and include:

- search input
- object type filter tabs or segmented control
- recent/current context rows
- result rows with object label, type, and status
- primary action: open frontend page in current tab
- secondary action: open backend edit screen in a new tab

Navigation should be explicit. Selecting a row should not mutate content or edit mode state.

### Safety Rules

- do not infer object editability from the current page's DOM
- do not expose private posts or terms the user cannot access
- terms must check `edit_term` before showing backend edit actions
- posts must check `edit_post` before showing backend edit actions
- frontend links can be omitted for non-public or unresolved objects

## Shared Globals

### Source Shape

Shared Globals is an upward-opening management surface for ACF option-page/shared global related-items fields.

Initial target:

- option-owned ACF `relationship` fields
- option-owned ACF `post_object` fields
- fields already proven by the current Visual Editor descriptor/session or discovered from safe ACF option-page field-group metadata

This is not a generic options editor. It is a governed collection editor for shared global related-object fields.

### Required Descriptor Metadata

A shared global collection candidate must identify:

- owner entity: `option`
- option page slug and label when available
- ACF field group key/title
- ACF field name/key/selector
- field type: `relationship` or `post_object`
- allowed referenced post types
- multiple/single behavior
- min/max constraints
- current stored ordered IDs
- mutation contract: shared relationship/post_object collection
- acknowledgement requirement: shared/global update
- reload-after-save behavior when affected rendered loops depend on that global field

Do not make a global field writable from this toolbar if the field group, option owner, field key, or reference type is ambiguous.

### Discovery Strategy

Use a staged approach:

1. Session-backed candidates
   - list shared option collection descriptors already present in the current page public map or hydrated descriptor cache
   - safest first slice because the current page already proved render ownership
2. Option-page inventory candidates
   - add a server endpoint that enumerates allowed ACF option-page relationship/post_object fields
   - requires `dbvc_visual_editor_option_capability`, defaulting to `manage_options`
   - returns metadata only, not arbitrary option values outside supported collection fields
3. Admin-curated allowlist if inventory is too broad
   - allow filters or settings to expose only selected option-page fields
   - current setting: `dbvc_visual_editor_shared_global_field_names`
   - default configured field: `settings_globals_default_posts`
   - accepted format: one ACF options field name per line or comma-separated

### UI Behavior

The popover should include:

- option page filter
- field search/filter
- field rows grouped by option page and field group
- selected field detail
- connected item list
- search/add/remove/reorder controls using the existing reference collection interaction model
- shared-option acknowledgement before save
- clear copy that this updates a shared global field and may affect multiple pages

The UI may reuse `createReferenceCollectionController()` patterns, but it should not require a visible page marker if the server creates a safe toolbar-scoped descriptor for the selected option field.

### Save Contract

Preferred first writable contract:

- new descriptor family or explicit source context for toolbar-managed shared options collections
- resolver can reuse `AcfReferenceCollectionResolver` only when it receives the same explicit field metadata required by current descriptors
- save runs through `MutationService`
- save runs through `MutationContractService`
- save requires shared acknowledgement
- journal records owner `option`, field group, option page, before/after ordered IDs, and referenced item metadata

Deferred:

- taxonomy collection mutation
- shared term/user collection mutation
- row-owned option-page nested collections
- repeater/flexible row lifecycle actions
- automatic cross-page DOM patching after save

## Active Object Link

The existing statusbar edit link should become a toolbar action.

Rules:

- default target is the current page/context object
- when a field panel is open, target switches to the active field owner if a backend link exists
- title/tooltip should name the object type where available
- unavailable links should render disabled, not hidden in a way that shifts the toolbar

This must keep the existing `resolveStatusBarEditLinkFromEntitySummary()` behavior until the new active-object link module is proven.

## Session And Mode Controls

Toolbar should surface session state without adding new authority:

- active indicator
- marker count
- session-expired warning
- refresh suggestion
- optional pause/hide markers action
- optional exit Visual Editor mode action

Mode toggles must reuse the existing edit-mode activation/deactivation path. Do not invent a second client-only mode flag that leaves server instrumentation active while the UI claims it is off.

## State Model

Add a narrow toolbar state object:

- `toolbarOpenPanel`
- `toolbarLastOpenPanel`
- `toolbarStatusMessage`
- `toolbarObjectSearch`
- `toolbarSharedGlobals`
- `toolbarPopoverPosition`

Keep these separate from:

- descriptor session
- descriptor cache
- selected marker
- panel open/drag state
- field index open subgroup/item state
- save-in-progress state

This separation keeps toolbar UI reversible and avoids turning the toolbar into a second descriptor store.

## Accessibility

Required:

- toolbar root has `role="toolbar"` and an accessible label
- every icon button has `aria-label`
- active/toggled buttons use `aria-pressed` or `aria-expanded`
- popovers have heading text and `role="dialog"` or a documented non-modal pattern
- focus moves into search popovers on open and returns to the triggering button on close
- `Escape` closes popovers before it clears selected markers
- keyboard users can reach field index rows, object search rows, and global collection controls
- reduced-motion users do not get animated dock/panel movement

## Mobile And Viewport Rules

- toolbar remains bottom-center with `width: min-content` until it would overflow
- on narrow viewports, collapse satellite buttons into the central dock or overflow menu
- popovers use `left/right: 12px` and full available width when needed
- popovers never cover the entire viewport unless the viewport is too small for a partial sheet
- field editor panel and toolbar popovers must not trap each other off-screen
- panel viewport clamping must account for toolbar height once the toolbar replaces the bottom-right statusbar

## Implementation Phases

### Phase 0: Audit And Wireframe

Status: planned.

Steps:

1. Inventory current statusbar actions and state.
2. Map each action to a toolbar button or popover.
3. Confirm which controls stay top-level versus overflow.
4. Verify panel bottom offset and z-index conflicts.
5. Document exact icon names/labels before implementation.

Acceptance:

- no statusbar behavior is left unmapped
- toolbar can be implemented without descriptor or save-contract changes

### Phase 1: Toolbar Shell

Status: implemented as a reversible first slice.

Files likely involved:

- `assets/js/overlay-app.js`
- `assets/css/overlay.css`
- `src/Assets/AssetLoader.php`

Steps:

1. Add `ensureToolbar()` and toolbar action binding.
2. Render fixed bottom-center dock and satellite buttons.
3. Add icon helper, labels, and tooltips.
4. Add shared upward popover manager.
5. Keep existing `.dbvc-ve-statusbar` visible until parity is verified behind a feature flag or internal switch.

Acceptance:

- toolbar renders only in Visual Editor mode
- toolbar does not render in Bricks Builder mode
- no save, descriptor, or panel behavior changes

First-slice implementation notes:

- `overlay-app.js` now creates a bottom-center `dbvc-ve-toolbar` with a dark central dock, circular satellite buttons, an active-object edit link, and the existing Visual Editor mode exit URL.
- Go to Object is enabled as navigation-only search, Shared Globals is enabled as session-backed inspect/launcher inventory, and overflow remains a disabled shell control until it has a concrete purpose.
- The toolbar uses the existing Visual Editor asset bootstrap and does not add a new runtime authority path.

### Phase 2: Statusbar Inside Toolbar

Status: implemented as a compatibility wrapper.

Steps:

1. Move statusbar summary into toolbar status module.
2. Move field index into an upward `Review fields` popover.
3. Preserve statusbar count, filters, grouped rows, `Locate`, and `Open`.
4. Preserve save/session messages.
5. Preserve active-object edit link behavior.
6. Remove or hide the old bottom-right statusbar only after parity.

Acceptance:

- all current field index QA remains true
- current/active edit link remains available
- session expiry is visible
- no eager descriptor hydration is introduced

First-slice implementation notes:

- `.dbvc-ve-statusbar` is still the status, message, active-link, and field-index renderer.
- The statusbar now parks inside the toolbar and moves into an upward toolbar popover when the status or review action opens.
- `Review fields` opens the existing field index through the current `state.fieldIndexOpen` path, so filters, subgroup/item state, `Locate`, and `Open` continue to use the same handlers.
- Save/session messages are mirrored into a compact toolbar message strip when the popover is closed.

### Phase 3: Go To Object Popover

Status: implemented for posts and terms.

Steps:

1. Add object-search REST controller if needed.
2. Add object type filters for posts and terms.
3. Add debounced search with capped results.
4. Add frontend/backend navigation actions.
5. Add current/recent object shortcuts from page context and field index where safe.

Acceptance:

- object search respects capabilities
- private/inaccessible objects are not exposed
- search result navigation does not mutate content
- frontend and backend links are escaped and explicit

First-slice implementation notes:

- Added `ObjectSearchController` at `GET /dbvc/v1/visual-editor/object-search`.
- The route requires active Visual Editor mode, `canUseVisualEditor()`, sanitized request parameters, and capped result output.
- Post results are filtered through `canEditPostId()` before frontend/backend URLs are exposed.
- Term results are filtered through `current_user_can('edit_term', term_id)` before frontend/backend URLs are exposed.
- The toolbar `Go to object` popover now provides All/Post/Term filters, debounced search, current object shortcut, and explicit frontend/backend navigation links.
- The feature is navigation-only; it does not load descriptors and cannot mutate content.

### Phase 4: Shared Globals Inspectable Inventory

Status: implemented for session-backed candidates and configured option-field inventory.

Steps:

1. List shared option collection candidates already proven by the current page/session.
2. Show option page, field group, field label, field type, and item count.
3. Load configured option-owned globals from the Visual Editor add-on settings allowlist.
4. Keep unsupported fields locked or omitted until toolbar-scoped descriptors and shared save contracts are proven.
5. Add locked explanations for unsupported or ambiguous candidates.

Acceptance:

- users can see which global related-items fields are relevant
- unsupported ACF fields do not appear as writable
- configured fields require option capability before metadata is exposed

First-slice implementation notes:

- The Shared Globals toolbar popover now lists option-owned `relationship` / `post_object` style candidates already present in the current page's lightweight session map.
- Entries are grouped by option page or field group metadata when available.
- The popover is inspect/launcher-only and routes `Open` through the existing marker/panel path, preserving current descriptor hydration, shared acknowledgement, and save-contract behavior.
- The Visual Editor settings area now includes `Shared global option field names`, defaulting to `settings_globals_default_posts`.
- Added `SharedGlobalFieldsController` at `GET /dbvc/v1/visual-editor/session/{session_id}/shared-global-fields`.
- The route requires active Visual Editor mode, base Visual Editor capability, the option capability used by `canEditDescriptor()` for option owners, a valid current session, and ACF field metadata.
- Configured fields must resolve by exact ACF options field name and must be `relationship` or `post_object`.
- The route creates toolbar-scoped descriptors with `source_context = toolbar_shared_global_option`, owner `option`, `acf_object_id = option`, existing shared collection contracts, shared acknowledgement, and reload-after-save behavior.

### Phase 5: Shared Globals Writable First Slice

Status: first configured-field slice implemented through existing collection panel/save contracts; broader inventory and conflict UX remain planned.

Steps:

1. Add toolbar-scoped shared option collection descriptor creation.
2. Reuse or extend the reference collection resolver with explicit option owner metadata.
3. Add search/add/remove/reorder controls.
4. Require shared-option acknowledgement before save.
5. Save through mutation contracts and journal before/after IDs.
6. Reload or show explicit reload recommendation after save.

Acceptance:

- only relationship/post_object option fields with exact metadata are writable
- saved IDs are validated against allowed referenced post types
- shared-option acknowledgement is required before save
- save uses the existing mutation service, cache invalidation, and journal path
- stale source conflict UX beyond existing collection validation remains pending
- journal entries identify option owner, field group, option page, and before/after IDs

First-slice implementation notes:

- The configured field list is environment-specific and stored in the DBVC Configure -> Add-ons -> Visual Editor settings area.
- The toolbar popover merges configured descriptors into the active session response and can open the normal editor panel without a visible marker.
- The panel still uses `createReferenceCollectionController()` for search/add/remove/reorder and `AcfReferenceCollectionResolver` for validation/saves.
- Configured shared globals reload after save by default because affected rendered loops may not be on the current page.

### Phase 6: Responsive And Accessibility Hardening

Status: planned.

Steps:

1. Test keyboard path through all toolbar actions.
2. Test narrow mobile layout.
3. Verify popover clamping above toolbar.
4. Verify panel clamping with toolbar present.
5. Verify reduced-motion handling.

Acceptance:

- toolbar controls remain reachable on desktop and mobile
- text does not overflow buttons or result rows
- no popover opens below the viewport

## Validation Matrix

Run the narrowest applicable checks per phase.

Statusbar migration:

- marker surfacing unchanged
- badge label/source classification unchanged
- panel load from field index `Open`
- descriptor payload unchanged
- save request unchanged
- post-save rendered value behavior unchanged
- reload-after-save behavior unchanged
- session expiry/refresh message still visible
- builder mode guard still blocks toolbar/status UI

Go To Object:

- post search for editable public posts
- post search excludes inaccessible posts
- term search for editable public taxonomy terms
- backend edit link capability behavior
- frontend navigation URL behavior
- empty search and no-results states

Shared Globals:

- session-backed shared option relationship field appears inspect-only first
- ambiguous option fields stay locked
- writable first slice requires acknowledgement
- search results honor allowed post types
- save request records shared option owner and field metadata
- reload/reconciliation path is explicit

Frontend:

- desktop screenshot at common viewport
- mobile screenshot at narrow viewport
- keyboard-only open/close/search/save paths
- Media Library interactions do not close toolbar/panel unexpectedly
- `git diff --check`

## Risks And Guardrails

Risks:

- toolbar becomes a second editor panel
- statusbar migration loses session/save messaging
- object search exposes inaccessible content
- shared global editing mutates broad option fields too casually
- popovers compete with the draggable editor panel
- mobile toolbar consumes too much viewport height

Guardrails:

- keep the field editor in `dbvc-ve-panel`
- keep toolbar popovers launcher-sized and focused
- keep initial shared globals inspect-only
- require exact option field metadata before shared collection saves
- require shared acknowledgement for every shared global mutation
- do not hydrate all descriptors to populate toolbar panels
- do not add object/global search endpoints without capability checks and response caps

## Recommended First Slice

Implement only:

1. toolbar shell behind a reversible migration path
2. shared upward popover manager
3. statusbar/field-index popover parity
4. active object edit-link button parity

Do not implement Go To Object or Shared Globals writes until statusbar parity is validated.

## Deferred

- persisted toolbar layout preferences
- user-customizable toolbar buttons
- bulk field actions
- global non-collection options editing
- relationship/flexible/repeater row lifecycle mutation
- cross-page live DOM patching after shared global saves
- materialized object inventory cache
