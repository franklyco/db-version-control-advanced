# R6-B — Frontend Site Manager Workspace: state, action, and integration contract

**Status:** Landed 2026-09-15 (docs-only slice; nothing here is implemented yet — R6-D implements it).
**Authority:** `releases/R6-FRONTEND-SITE-MANAGER-WORKSPACE.md` (scope) → this document (runtime contract) → `ui-ux/R6-WORKSPACE-CLAUDE-MOCKUP-HANDOFF.md` (mockup brief). When the code lands, the code wins; when the code and this doc disagree before then, this doc wins over the mockup.
**Companions:** E-142 (R6-A read model), D-065–D-068 (maintainer-confirmed R6 pins), D-061 (R3 drawer shell), D-058 (desktop-only, permanent).

---

## 0. What this document freezes

R6-B is the "define before you draw" step required by `ui-ux/CLAUDE-CODE-MOCKUP-HANDOFF.md` §Required sequence (steps 2–3): the **state shape**, the **action vocabulary**, the **event/API contract**, the **layering + focus + Escape precedence**, the **persistence rules**, the **mode-preservation/routing rules**, the **feature-flag + bootstrap payload**, and the **state matrix** the R6-C mockup must cover. R6-D-1/2/3 implement it; R6-E hardens it.

Non-goals restated (see release spec §Out of scope): no favorites/pins, no named workspaces, no bulk ops, no object creation/deletion, no user enumeration (D-066), no attachment enumeration (D-067), no second field editor, no mobile variants (D-058).

---

## 1. Surfaces and the layering stack

The workspace joins six existing frontend surfaces. Their z-order is fixed by `overlay.css` tokens; R6 adds **one** token.

| Layer (top → bottom) | Surface | z token | Owner |
|---|---|---|---|
| 7 | WordPress `wp.media` modal (`.media-modal`, `.media-modal-backdrop`) | WP core (`560000`, `media-views.css`) | core |
| 6.5 | Palette bulk editor backdrop (`.dbvc-ve-palette-bulk-popover-backdrop`) — drawer-launched modal | `--dbvc-ve-z-modal` 120030 (E-148) | `overlay-app.js` |
| 6 | Frontend floating toolbar `.dbvc-ve-toolbar` + its popovers (Status, Review Fields, Go To Object, Shared Globals) | `--dbvc-ve-z-toolbar` 120020 | `overlay-app.js` |
| 5 | Brand Control Center drawer `.dbvc-ve-control-center` (left, 480px) | `--dbvc-ve-z-drawer` 120015 | `brand-control-center-app.js` |
| 4 | Main editor panel `.dbvc-ve-panel` (movable, sessionStorage position) **and** Media Manager modal `.dbvc-ve-media-manager` (both use the panel token; the toolbar closes MM before opening the BCC so they never coexist) | `--dbvc-ve-z-panel` 120010 | `overlay-app.js` / `media-manager-app.js` |
| **3 (new)** | **Workspace drawer `.dbvc-ve-workspace`** (left, same slot as the BCC) | **`--dbvc-ve-z-workspace` = 120005** (new token in `overlay.css` `:root`; sits between the badge layer and the panel so every existing surface paints over it) | `workspace-app.js` (new) |
| 1 | Badge layer / section badges / status bar | 119970–119990 | `overlay-app.js` |

**D-065 in practice:** workspace and BCC share the left slot. Opening the BCC from the workspace leaves the workspace mounted and open underneath (`state.isOpen` stays `true`); the BCC's `--dbvc-ve-z-drawer` (120015) paints over the workspace's 120005. When the BCC closes, the workspace is simply visible again — no re-open, no re-fetch, no focus jump other than the BCC's own focus restoration to its trigger (which will be the workspace's "Brand & Globals" entry button). The mockup must show this stacked state (§9 state S-11).

**Panel vs. workspace:** the panel's z (120010) is *above* the workspace (120005), so a dragged panel could cover the drawer's list. R6-D-3 insets the panel's clamp bounds by the drawer width while the workspace is open (see §5.4) so the two never overlap. The panel is never re-parented into the drawer. The Media Manager modal (also 120010) paints over the workspace, which is the intended S-12 stack.

---

## 2. Workspace state shape

One IIFE (`addons/visual-editor/assets/js/workspace-app.js`, sibling to `brand-control-center-app.js`, enqueued only when the flag is on) owns exactly this state. Field names are the production names R6-D-1 must use; the mockup fixture mirrors them.

```js
state = {
  // shell
  root: HTMLElement|null,          // .dbvc-ve-workspace, created lazily on first open
  trigger: HTMLElement|null,       // toolbar button that opened it (focus restoration target)
  isOpen: boolean,                 // drawer visible
  section: 'navigate'|'tools',     // active section tab in the drawer body (see §4.1)

  // object navigation (R6-A read model)
  types: Array<TypeDescriptor>,    // from GET object-search?includeTypes=1; fetched once per page load on first open
  typesStatus: 'idle'|'loading'|'ready'|'error',
  activeType: { objectType: 'all'|'post'|'term', subtype: string },   // 'all' = interleaved posts+terms
  // R6.1-b: `kind` is NOT a separate field — it is activeType.objectType (one source of truth);
  // the kind control writes objectType with subtype ''. getState() exposes it as `kind`.
  sort: 'recent'|'title_asc'|'title_desc'|'newest'|'oldest'|'relevance',   // R6.1-b; 'relevance' only while search !== ''
  autoRelevance: boolean,          // R6.1-b: true when the app (not the user) switched recent → relevance on typing
  search: string,                  // ≤ 100 chars, debounced 180 ms (matches Go To Object)
  results: Array<ObjectItem>,      // accumulated pages for the current query
  page: number,                    // last page fetched (1-based)
  hasMore: boolean,
  requestId: number,               // stale-response guard (matches toolbarObjectSearchRequestId pattern)
  requestStatus: 'idle'|'loading-initial'|'loading-more'|'ready'|'error',
  requestError: { message: string, status: number }|null,
  focusedItemKey: string|null,     // `${objectType}:${id}` — focus continuity across rerender

  // current object (from bootstrap.pageContext + bootstrap.currentEditLink; no request)
  currentObject: ObjectItem|null,

  // integration
  overlay: 'none'|'control-center'|'media-manager',  // which sibling surface is painted above us
  modeStatus: 'active'|'unsafe',   // §6 — 'unsafe' when mode cannot be preserved for a navigation target

  // persistence (localStorage, try/catch wrapped — §7)
  persisted: { isOpen: boolean, section: string, activeType: {objectType, subtype}, sort }   // sort: R6.1-b, never 'relevance'
}
```

`TypeDescriptor` and `ObjectItem` are exactly the R6-A payload shapes (E-142):

```text
TypeDescriptor: { objectType:'post'|'term', subtype, label, singularLabel, hierarchical, viewable }
ObjectItem:     { objectType, id, title, subtype, typeLabel, status, statusKey, frontendUrl,
                  hasFrontendRoute, backendUrl, canEdit }
```

Nothing else crosses the wire. No raw field keys, owner ids, nonces beyond the existing `wp_rest` nonce, or write payloads exist in this contract.

---

## 3. Action vocabulary and event contract

### 3.1 DOM action hooks (production)

Every interactive element inside `.dbvc-ve-workspace` carries `data-dbvc-ve-workspace-action="<action>"`. The delegated click handler on the root dispatches on this attribute (same pattern as `data-dbvc-ve-control-center-action` and `data-dbvc-ve-toolbar-action`). The mockup uses `data-mockup-*` only and must not claim these names.

| Action | Element | Effect | Request? |
|---|---|---|---|
| `close` | header × | `close({restoreFocus:true})` | no |
| `section` (+ `data-dbvc-ve-workspace-section`) | section tabs | switch `state.section`; persist | no |
| `type` (+ `data-dbvc-ve-workspace-type`, `-subtype`) | type tabs/chips | set `activeType`, reset `results/page`, fetch page 1. R6.1-b: the strip is filtered by kind, and its own "All" tab carries `type = <current kind>` (`all` / `post` / `term`), `subtype ''` | yes (object-search) |
| `kind` (+ `data-dbvc-ve-workspace-kind` = `all`\|`post`\|`term`) — R6.1-b | segmented `role="radiogroup"` (`[data-dbvc-ve-workspace-kinds]`), ←/→ roving | set `activeType = {objectType: kind, subtype: ''}`, persist, fetch page 1; re-selecting the checked kind is a no-op | yes |
| `sort` — R6.1-b | `<select>` (`change` event, not click) | set `sort`, persist (unless `relevance`), fetch page 1; options = the five browse orders, plus **Best match** only while `search !== ''` | yes |
| `search` | `<input type="search">` (input event, not click) | set `search`, debounce, fetch page 1 | yes |
| `clear-search` | × inside search | `search=''`, fetch page 1 | yes |
| `retry` | error state button | refetch current page | yes |
| `load-more` | footer button | fetch `page+1`, append | yes |
| `open-frontend` (+ `data-dbvc-ve-workspace-object`) | result row primary when `hasFrontendRoute` | `window.location.assign(frontendUrl)` — same-tab, mode preserved by cookie (§6) | no |
| `open-backend` | result row secondary / primary when no route | `window.open(backendUrl, '_blank', 'noopener')` | no |
| `current-frontend` / `current-backend` | current-object card | same as above for `currentObject` | no |
| `tool-review-fields` | tools section | `dispatch('dbvc:visual-editor:review-fields:open', {trigger})` → overlay-app opens the existing Review Fields popover (§3.2/§3.3) | no |
| `tool-control-center` | tools section | `dispatch('dbvc:visual-editor:control-center:toggle', {trigger})`; `state.overlay='control-center'` | no |
| `tool-media-manager` | tools section | `dispatch('dbvc:visual-editor:media-manager:toggle', {trigger})`; `state.overlay='media-manager'` | no |
| `tool-edit-object` | tools section | `window.open(currentEditLink.url, '_blank')` — only rendered when `bootstrap.currentEditLink` exists | no |
| `tool-exit` | tools section | `window.location.assign(bootstrap.toggleUrl)` — the existing nonce'd toggle URL; **never** a bespoke URL | no |

Nothing in the workspace writes. The **only** field-editing surface remains the main panel, reached via Review Fields / BCC / markers — exactly as today.

### 3.2 Custom events

Namespace follows the two existing apps.

| Event | Direction | Detail | Notes |
|---|---|---|---|
| `dbvc:visual-editor:workspace:toggle` | toolbar → workspace | `{ trigger }` | toolbar button click (R6-D-1 adds `data-dbvc-ve-toolbar-action="workspace"`) |
| `dbvc:visual-editor:workspace:open` / `:close` | any → workspace | `{ trigger?, restoreFocus? }` | |
| `dbvc:visual-editor:workspace:opened` / `:closed` | workspace → any | `{}` | overlay-app listens to update the toolbar button `aria-expanded` and the panel clamp inset (§5.4) |
| `dbvc:visual-editor:control-center:opened` / `:closed` | BCC → workspace | `{}` | workspace sets/clears `state.overlay` (already emitted by BCC — no BCC change) |
| `dbvc:visual-editor:media-manager:opened` / `:closed` | MM → workspace | `{}` | same (already emitted by MM) |
| `dbvc:visual-editor:review-fields:open` | workspace → overlay-app | `{ trigger }` | **new listener in overlay-app** (R6-D-3) → `openStatusBarToolbarPopover({trigger, expandIndex:true})`; overlay-app exposes no global object (only `DBVCVisualEditorApi`), so the bridge is event-based like `absorb-descriptor` / `open-palette-bulk` |

### 3.3 Public API

`window.DBVCVisualEditorWorkspace = { open, close, toggle, isOpen, getState, navigate({objectType, subtype, search, sort}) }` — mirrors `DBVCVisualEditorBrandControlCenter`. `getState()` returns a defensive copy for the jsdom suite (R6.1-b adds `kind` and `sort`).

`overlay-app.js` gains **no** new global. Its only R6-D-3 additions are the `review-fields:open` listener above and the panel-clamp inset (§5.4), both covered by the overlay-app jsdom suite.

### 3.4 API client

`api-client.js` `searchObjects(search, objectType)` is extended **backward-compatibly** to `searchObjects(search, objectType, options = {})` where `options` = `{ subtype, page, perPage, includeTypes, sort }` (`sort` from R6.1-a; the popover never sends it and keeps today's order — D-073). Existing popover call sites keep the two-arg form. Response is passed through untouched (`items`, `page`, `perPage`, `hasMore`, `query`, `types?`).

---

## 4. Drawer anatomy (production contract; the mockup renders this)

Inherits D-061's shell geometry: left-anchored, fixed **480px** width, `top: 32px` (admin bar), `bottom: 76px` (toolbar strip), no backdrop, site visible to the right, slide-in respecting `prefers-reduced-motion`, internal scroll only in the results region, dark-mode via the existing token cascade (R5.later-darkmode.c) with **zero** new colors.

```text
.dbvc-ve-workspace                      role="complementary" aria-label="Site Manager Workspace"
├── __header                            title + close (×)
├── __current                           current-object card (title, typeLabel, statusKey chip,
│                                       Open (if hasFrontendRoute) / Edit (if backendUrl)); hidden when null
├── __sections   role="tablist"         [ Navigate ] [ Tools ]  — arrow-key nav, aria-selected
├── __body[section=navigate]
│   ├── __search                        <input type="search"> + clear; aria-describedby results count
│   ├── __controls   (R6.1-b)           __segmented role="radiogroup" [ All | Content | Taxonomies ]
│   │                                   + __sort <select> (visually-hidden <label> "Sort"); one 32px row
│   ├── __types      role="tablist"     All · Pages · Posts · <each CPT> · <each taxonomy>   (from types[],
│   │                                   filtered by kind — R6.1-b; the strip's "All" = all types of that kind)
│   │                                   horizontally scrollable, never wraps; `viewable:false` types get
│   │                                   a "backend only" affix in their accessible name
│   ├── __status     aria-live=polite   "Searching…" / "12 results" / error text  (single live region)
│   ├── __results    <ul role=list>     one <li> per ObjectItem (row anatomy below)
│   └── __footer                        Load more (hasMore) | "No more results"
└── __body[section=tools]
    ├── Review Fields                   → tool-review-fields
    ├── Brand & Globals                 → tool-control-center   (only when controlCenter.enabled)
    ├── Media Manager                   → tool-media-manager    (only when mediaManager.enabled;
    │                                     shows latest scan status line when the MM app exposes it —
    │                                     read via window.DBVCVisualEditorMediaManager.getState())
    ├── Edit active object              → tool-edit-object      (only when currentEditLink)
    └── Exit Visual Editor              → tool-exit
```

**Row anatomy** (`__row`): leading type glyph (page/post/term/CPT generic), `__title` (2-line clamp, `title=` full text), `__meta` = `typeLabel · status` (status text always present — never color-only), trailing actions: **primary** = `Open` when `hasFrontendRoute`, else `Edit` (backend, new tab, with "opens in new tab" in the accessible name); **secondary** = `Edit` when both exist. Rows with neither (should not happen — `canEdit` is always true — but fail safe) render a disabled "No route" text.

Result ordering is server order, selected by `sort` (R6.1-a/b): `recent` = `modified DESC, ID DESC` (default; terms `term_id DESC`), `title_asc` / `title_desc`, `newest` / `oldest` (publish date; terms `term_id`), `relevance` (search only; terms `name ASC`). Typing into an empty search with `recent` active switches to `relevance` automatically and back to `recent` when the search is cleared; an explicit non-default choice is left alone. The status line names the order when it is not the default ("Showing 20 · more available · Title A → Z"). No client-side sorting.

---

## 5. Coexistence rules

### 5.1 Toolbar popovers
- Opening the workspace closes any open toolbar popover (same as the `control-center` toolbar action today).
- Opening a toolbar popover while the workspace is open **does not** close the workspace. Popovers float above it (z 120020).
- Outside-click does **not** close the workspace (persistent surface, D-061 precedent). It still closes popovers as today.
- The Go To Object popover remains available as the rollout fallback (spec §Compatibility). R6 does not remove it; retirement is a post-R6-E decision.

### 5.2 Brand Control Center
- Workspace → BCC: workspace stays open under the BCC (D-065). `state.overlay='control-center'`; the workspace root gets `aria-hidden="true"` + `inert` while overlaid so its controls are not tabbable underneath.
- BCC close → `control-center:closed` event → workspace clears `overlay`, removes `inert`, and **completes the focus hand-back itself**: the BCC restores focus to its trigger *before* emitting `:closed`, while the workspace root is still `inert`, so that call bounces; when focus is stranded (`<body>`, null, or inside the closed BCC) the workspace focuses the Brand & Globals tool it launched from (`state.lastToolTrigger`). Focus that legitimately went elsewhere is left alone. (Amended R6-D-3, E-149.)
- Toolbar BCC button while workspace open: identical to the above.

### 5.3 Media Manager and the WordPress media modal
- Workspace → Media Manager: workspace stays open under the modal with `inert`, `state.overlay='media-manager'`; MM close restores focus to its trigger (the workspace button).
- `wp.media` modal (opened by image fields in the main panel): always topmost; the workspace ignores Escape while `.media-modal:not([style*="display: none"])` exists or `isWpMediaModalElement(document.activeElement)` is true.

### 5.4 Main editor panel (movable)
- The panel stays authoritative for edits and keeps its `sessionStorage` position.
- When the workspace is open, `overlay-app.js` `getPanelViewportBounds()` insets `left` by the drawer width (read from the CSS custom property `--dbvc-ve-workspace-inset` set on `document.documentElement` by the workspace: `480px` open / `0` closed) so `clampPanelPosition` cannot place the panel over the drawer. On `workspace:closed` the inset returns to 0 and the panel is re-clamped once (`applyPanelPosition`).
- No other panel behavior changes. Opening a field from Review Fields with the workspace open leaves the workspace open.

### 5.5 Bricks Builder and unsupported contexts
- `FrontendRuntimeGuard::shouldRunFrontend()` already prevents the whole overlay stack in the builder; the workspace enqueues only inside the existing `shouldLoadFrontendAssets()` gate, so no builder-specific code is needed. R6-E adds an explicit PHPUnit assertion that `workspace.enabled` is absent from the bootstrap when the guard is off.

---

## 6. Focus, keyboard, and Escape precedence

**Open:** focus moves to the drawer's close button on the next animation frame (BCC precedent), the toolbar trigger gets `aria-expanded="true"`.
**Close:** focus returns to `state.trigger` when connected, else to the toolbar's workspace button, else to `document.body`.
**Tab order inside:** header × → current-object actions → section tabs → (Navigate) search → clear → type tabs → rows (each row: primary, secondary) → Load more; (Tools) tool buttons top-to-bottom. No focus trap — it is a complementary landmark, not a modal.
**Arrow keys:** `ArrowLeft/Right` within `__sections` and `__types` tablists move selection (roving tabindex, BCC R4-C-2 precedent). `ArrowUp/Down` inside `__results` move between rows' primary actions; `Home/End` jump.
**Rerender focus continuity:** after a fetch, if `focusedItemKey` still exists in `results`, focus returns to that row's primary action; else to `__status`.

**Escape precedence (capture-phase `keydown` on `document`, one handler in `workspace-app.js`):**

1. `wp.media` modal open → ignore (core handles it).
2. Media Manager open (`DBVCVisualEditorMediaManager.isOpen()`) → ignore (MM handles it).
3. BCC open (`DBVCVisualEditorBrandControlCenter.isOpen()`) → ignore (BCC handles it).
4. Toolbar popover open (`document.querySelector('.dbvc-ve-toolbar-popover:not([hidden])')`) → ignore (toolbar handles it).
5. Main panel open and focus inside `.dbvc-ve-panel` → ignore (panel owns it).
6. Otherwise, if the workspace is open → `close({restoreFocus:true})`, `preventDefault`, `stopPropagation`.

The BCC and toolbar handlers run in capture phase on `document` and call `preventDefault` + `stopPropagation` when they act — but `stopPropagation` does **not** stop later listeners on the same node, and by the time the workspace handler runs the sibling may already report closed. The workspace handler therefore yields first on `event.defaultPrevented || event.cancelBubble`, then on the live predicates above; it is registered after both siblings (enqueue dependency order) so those flags are already set. (Amended R6-D-3, E-149.)

---

## 7. Persistence

- **Store:** `window.localStorage` key `dbvc-ve-workspace:v1` → `{ isOpen, section, activeType, sort }` (`sort` added in R6.1-b; the kind is already carried by `activeType.objectType`). Every read/write wrapped in try/catch; absence or parse failure → defaults `{isOpen:false, section:'navigate', activeType:{objectType:'all', subtype:''}, sort:'recent'}`. `relevance` is search-scoped and **never persisted** — the store keeps the last non-relevance sort; a persisted `relevance` (hand-edited) is read as `recent`.
- **Why localStorage, not sessionStorage:** the release requires a *persistent* workspace across supported frontend navigation; sessionStorage is per-tab and would lose state in the common "open object in same tab" flow. The panel keeps sessionStorage for its position (unchanged).
- **What is deliberately not persisted:** `search` text and `results` (fresh on every page — avoids stale routes), `page`, `overlay`, scroll position. A "minimal current/recent object shortcut" is **not** implemented (spec allows it only if free; it is not — D-069).
- **Restore:** on page load with the flag on and `persisted.isOpen === true`, the workspace opens **without** moving focus (no focus steal on navigation) and fetches `types` + page 1 for the persisted `activeType`. The toolbar button reflects `aria-expanded="true"`. A persisted `activeType` whose subtype discovery no longer offers falls back to All; a **kind-only** `activeType` (`{post,''}` / `{term,''}`, R6.1) is kept as long as discovery offers at least one type of that kind (E-157).
- **Startup override (VE-prefs-2, D-081):** the viewer preference `workspaceStartup` in `dbvc-ve-preferences:v1` (owned by overlay-app; read-only here, read once at mount) decides whether the persisted `isOpen` is honoured: `remember` (default) → as above; `open` → open without moving focus regardless of `isOpen`; `closed` → stay closed. The persisted `isOpen` is never rewritten by the override.
- **Kill switch:** when the option is off, the bootstrap omits `workspace`, the script is not enqueued, and stale localStorage is inert (nothing reads it). Re-enabling restores it.

---

## 8. Mode preservation and routing

- Mode is the `dbvc_visual_editor_mode=1` cookie (`EditModeState`). Same-tab navigation to any supported singular/term frontend URL keeps mode active with **no URL parameters** — the workspace never appends `dbvc_visual_editor`, `_dbvcve_nonce`, field, owner, or token params to object URLs.
- `frontendUrl` is only present when the server judged the object viewable (R6-A honesty rule). The client never synthesises a frontend URL from an id.
- Backend links open in a new tab (`noopener`) so the frontend session and the workspace state survive.
- **Unsafe-mode status:** if a frontend navigation lands on a page where the overlay does not mount (unsupported context, redirect to a non-singular URL, permission change), nothing in R6 can run there by definition. The contract therefore places the honesty *before* navigation: rows whose `hasFrontendRoute` is false never offer `Open`, and the current-object card shows "Visual Editor is not available on this page" only when `pageContext.isSupported === false` is echoed by the bootstrap (already emitted). A safe route back is always the backend `Edit` link plus the toolbar's Exit button.
- Exit uses `bootstrap.toggleUrl` (nonce'd) — identical to the toolbar power button.

---

## 9. State matrix (mockup + jsdom coverage targets)

| ID | State | Trigger | Visible result |
|---|---|---|---|
| S-01 | Closed | default / persisted `isOpen:false` | toolbar button only, `aria-expanded=false` |
| S-02 | Open · Navigate · populated | first open, `types` ready, page 1 ready | current card + type tabs + ≥ 12 rows + Load more |
| S-03 | Types loading | first open before `includeTypes` returns | tabs skeleton, search disabled, status "Loading object types…" |
| S-04 | Results loading-initial | type/search change | rows region shows 3 skeleton rows, status "Searching…" |
| S-05 | Results loading-more | Load more | existing rows stay; button shows spinner + "Loading…" (disabled) |
| S-06 | Empty type | a CPT with zero editable objects | "No {label} yet." + backend "Add new" **not** offered (creation out of scope) |
| S-07 | No search matches | search with 0 hits | "No matches for “{search}” in {label}." + Clear search |
| S-08 | Error / retry | non-2xx or network | status text + Retry; previous rows remain (never wipe on error) |
| S-09 | Permission-filtered / unavailable | user lacks type caps, or 403 mode-inactive | type absent from tabs; 403 → "Visual Editor mode is no longer active. Refresh the page." |
| S-10 | Object without frontend route | draft / pending / scheduled / non-queryable term | row primary = Edit (new tab), meta shows status, no Open |
| S-11 | BCC overlaying workspace | Tools → Brand & Globals | BCC drawer painted over an inert workspace; both visible in the composite |
| S-12 | Media Manager over workspace | Tools → Media Manager | MM modal over inert workspace |
| S-13 | Coexistence with main panel | Review Fields → field open | panel clamped to the right of the 480px drawer; drawer unchanged |
| S-14 | Long labels / large result set | 60-char titles, 30-row page | 2-line clamp with `title=`; internal scroll; sticky search/type strip |
| S-15 | Keyboard focus | tab through | visible `:focus-visible` rings; roving tabindex on both tablists |
| S-16 | Reduced motion | `prefers-reduced-motion: reduce` | no slide; instant show/hide |
| S-17 | Dark mode | `prefers-color-scheme: dark` | token cascade only |
| S-18 | Tools section | Tools tab | 3–5 tool entries with availability gating (BCC/MM flags, edit link) |
| S-19 | Persisted reopen | reload with `isOpen:true` | drawer open on load, no focus steal, page 1 fetched |
| S-20 | Kind filter + sort (R6.1-b) | kind = Taxonomies, sort = Title A → Z | strip narrowed to taxonomies (its All = `objectType=term`), select shows the order, status "Showing n · Title A → Z"; Best match only while searching |

---

## 10. Feature flag, bootstrap, enqueue, i18n (R6-D-1 contract)

- **Option:** `DBVC_Visual_Editor_Addon::OPTION_WORKSPACE_ENABLED = 'dbvc_visual_editor_workspace_enabled'`, `add_option` default `'0'`, sanitised in the existing settings handler, exposed as `is_workspace_enabled()` = master switch AND this option (identical shape to `is_control_center_enabled()`). Default **off** → rollout fallback = today's toolbar/popovers (acceptance: "Disabling the workspace restores prior navigation behavior").
- **Bootstrap:** `'workspace' => ['enabled' => bool, 'restBase' => rest_url('dbvc/v1/visual-editor')]` next to `controlCenter`. Absent when disabled.
- **Enqueue:** `dbvc-visual-editor-workspace` style + script, deps `['dbvc-visual-editor-overlay']` (+ `dbvc-visual-editor-control-center` when that is enabled, so mount order guarantees §6 handler ordering), only inside the existing `if ($…_enabled)` ladder in `AssetLoader::enqueue()`.
- **Toolbar:** one new dock button `workspace` (icon `grid`), rendered only when enabled, placed first in the dock (before Review Fields) per the reference concept; `aria-controls="dbvc-ve-workspace"`, `aria-expanded`, `aria-haspopup="false"` (it is a landmark, not a dialog).
- **i18n key family:** `workspace*` in `AssetLoader` strings (e.g. `workspaceTitle`, `workspaceClose`, `workspaceSectionNavigate`, `workspaceSectionTools`, `workspaceSearchPlaceholder`, `workspaceTypeAll`, `workspaceStatusSearching`, `workspaceStatusResults`, `workspaceEmptyType`, `workspaceEmptySearch`, `workspaceError`, `workspaceRetry`, `workspaceLoadMore`, `workspaceOpenFrontend`, `workspaceOpenBackend`, `workspaceOpensNewTab`, `workspaceNoRoute`, `workspaceCurrentLabel`, `workspaceToolReviewFields`, `workspaceToolControlCenter`, `workspaceToolMediaManager`, `workspaceToolEditObject`, `workspaceToolExit`, `workspaceModeInactive`). Final copy is proposed by the mockup and approved by the maintainer in R6-C.
- **CSS:** new `addons/visual-editor/assets/css/workspace.css`, prefix `.dbvc-ve-workspace`, BEM elements/`is-*` modifiers, tokens from `overlay.css` only plus scoped `--dbvc-ve-workspace-*` sizing variables; one new z token `--dbvc-ve-z-workspace` in `overlay.css`.

---

## 11. Implementation slices this contract feeds

| Slice | Implements | jsdom / PHPUnit targets |
|---|---|---|
| R6-C | Static mockup + decision record from `ui-ux/R6-WORKSPACE-CLAUDE-MOCKUP-HANDOFF.md` | — |
| R6-D-1 | Flag + bootstrap + enqueue + toolbar button + `workspace-app.js` shell (§2 shell fields, §3.2 events, §3.3 API, §6 open/close/Escape, §7 persistence) | new `tests/visual-editor-workspace-state.test.cjs`: open/close/toggle, Escape precedence table, persistence round-trip, inert-under-overlay; PHPUnit: option default off, bootstrap absent when off |
| R6-D-2 | §4 Navigate section against the R6-A read model, §3.4 client extension, S-02…S-10, S-14, S-19 | jsdom: types fetch-once, debounce, stale guard, load-more append, error keeps rows, honest-route row rendering, focus continuity |
| R6-D-3 | §4 Tools section, §5.2–5.4 coexistence (BCC/MM overlay + inert, panel inset), `openReviewFields` bridge | jsdom (overlay suite): panel clamp inset on `workspace:opened/closed`; workspace suite: overlay state from BCC/MM events |
| R6-E | §5.5 builder assertion, permission boundary, large-page perf, keyboard/AT pass, release notes + rollback | PHPUnit + real-Chrome QA |

---

## 12. Pinned decisions recorded by this slice

- **D-069** — No recent-object list in R6 (spec allowed one only "if it directly reuses existing session behavior"; there is no such session list today, so it would be new scope).
- **D-070** — Workspace state persists in `localStorage` (`dbvc-ve-workspace:v1`), scoped to `{isOpen, section, activeType}`; search/results never persist.
- **D-071** — Escape precedence is media modal › Media Manager › BCC › toolbar popover › focused main panel › workspace; workspace is a non-modal `complementary` landmark, no focus trap, outside-click does not close it.
- **D-072** — The workspace's z token (120005) sits **below** the panel/Media Manager (120010) and the BCC (120015) so every existing surface keeps painting over it unchanged; panel/drawer overlap is prevented by insetting the panel's clamp bounds via `--dbvc-ve-workspace-inset`, not by re-ordering any existing z token.
- **D-073** — The Go To Object popover stays as the rollout fallback through R6-E; retirement is a separate post-R6 decision after equivalent behavior is verified.
