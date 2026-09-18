# R6 Site Manager workspace — wiring schematic (final, D4 2026-09-15)

Maps each mockup region to its **production** action, request, server symbol, states, and focus behaviour. Nothing in the static files performs any of this — every hook is `data-mockup-*`. Authority: `docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/R6-WORKSPACE-STATE-CONTRACT.md`; backend: R6-A (`ObjectNavigationReadModel`, E-142). Format follows `../r1-media-manager/WIRING-SCHEMATIC.md`.

## 1. Request flow

```text
[toolbar Workspace button click]  ─ data-dbvc-ve-toolbar-action="workspace"
    │  dispatch dbvc:visual-editor:workspace:toggle { trigger }
    ▼
[workspace-app.js open()]  ─ root created lazily; focus → __close; toolbar aria-expanded=true
    │  persisted.isOpen = true (localStorage dbvc-ve-workspace:v1)
    ▼
GET /wp-json/dbvc/v1/visual-editor/object-search?includeTypes=1&perPage=1&objectType=all
    │  ← permission_callback canUseVisualEditor + isRestRequestAuthorized (mode cookie)
    │  ← ObjectNavigationReadModel::describeTypes()  →  types[]   (once per page load)
    ▼
[__types renders: All + one tab per type]        typesStatus: ready
    │
    ▼
GET …/object-search?search=&objectType=all&subtype=&page=1&perPage=20
    │  ← ObjectNavigationReadModel::search()  →  items[], page, perPage, hasMore, query
    │  ← requestId guard: stale responses dropped
    ▼
[__results renders 20 rows · __status "Showing 20 · more available" · __footer Load more]
    │
    ├─ [type tab] ──► same GET with objectType/subtype, page=1 (results reset)
    ├─ [search input, 180 ms] ─► same GET with search=, page=1
    ├─ [Load more] ──────────► same GET page+1 → rows appended, focus → first new row
    ├─ [row Open] ───────────► window.location.assign(frontendUrl)   (mode preserved by cookie)
    ├─ [row Edit] ───────────► window.open(backendUrl, '_blank', 'noopener')
    └─ [Tools ▸ …] ──────────► existing surfaces via events (see §3); workspace stays open
```

## 2. Selector → wiring table

| Selector | Production action | Request | Server symbol | States | Focus |
|---|---|---|---|---|---|
| `.dbvc-ve-workspace` | — | — | — | open · is-closed · is-inert | on open → `__close`; on close → `state.trigger` |
| `__close` | `close` | — | — | — | → trigger (toolbar Workspace button) |
| `__current-row __action--primary` | `current-backend` | — | bootstrap `currentEditLink` | hidden when no link | n/a (new tab) |
| `__section-tab[navigate|tools]` | `section` | — | — | aria-selected | roving; ArrowLeft/Right |
| `__search` | `search` | `GET object-search?search=…&page=1` | `ObjectNavigationReadModel::search` | disabled while S-03 | stays in input; `__status` announces |
| `__search-clear` | `clear-search` | `GET …search=&page=1` | `search` | hidden when empty | → `__search` |
| `__type` (All + `types[]`) | `type` | `GET …objectType=&subtype=&page=1` | `search` (+ `describeTypes` for the list) | aria-selected; is-backend-only | roving; ArrowLeft/Right; results → `__status` |
| `__status` | — (output) | — | — | default · is-error | polite live region (single) |
| `__row __action--primary` (route) | `open-frontend` | — (navigation) | `hasFrontendRoute` from `buildPostItem/buildTermItem` | is-{statusKey} | n/a |
| `__row __action--primary` (no route) / `--secondary` | `open-backend` | — | `backendUrl` (`get_edit_post_link` / `get_edit_term_link`) | is-no-route | n/a (new tab) |
| `__load-more` | `load-more` | `GET …page=N+1` | `search` | is-loading · replaced by `__end` | → first appended row |
| `__retry` | `retry` | current page again | `search` | S-08 only | → first row or `__status` |
| `__tool[review-fields]` | `tool-review-fields` | — | overlay-app listener `dbvc:visual-editor:review-fields:open` → `openStatusBarToolbarPopover({expandIndex:true})` | S-18 | → popover; back to tool on close |
| `__tool[control-center]` | `tool-control-center` | — | `dbvc:visual-editor:control-center:toggle` (BCC) | S-11 (workspace inert) | BCC restores to this button |
| `__tool[media-manager]` | `tool-media-manager` | — | `dbvc:visual-editor:media-manager:toggle` (MM) | S-12 (workspace inert) | MM restores to this button |
| `__tool[edit-object]` | `tool-edit-object` | — | bootstrap `currentEditLink` | unavailable when null | n/a (new tab) |
| `__tool[exit]` | `tool-exit` | navigation to `bootstrap.toggleUrl` | `EditModeState::buildToggleUrl` | — | n/a |

## 3. Event contract touched (from contract §3.2)

| Event | Emitter → listener | Used by |
|---|---|---|
| `dbvc:visual-editor:workspace:toggle` / `:open` / `:close` | toolbar / any → workspace-app | toolbar button |
| `dbvc:visual-editor:workspace:opened` / `:closed` | workspace-app → overlay-app | toolbar `aria-expanded`; panel clamp inset `--dbvc-ve-workspace-inset` |
| `dbvc:visual-editor:control-center:opened` / `:closed` | BCC → workspace-app | `state.overlay`, `inert` toggle (S-11) |
| `dbvc:visual-editor:media-manager:opened` / `:closed` | MM → workspace-app | `state.overlay`, `inert` toggle (S-12) |
| `dbvc:visual-editor:review-fields:open` | workspace-app → overlay-app (**new listener**, R6-D-3) | Tools ▸ Review fields |

## 4. State diagrams

```text
requestStatus:  idle ─► loading-initial ─► ready ─► loading-more ─► ready
                              │                │
                              └──► error ◄─────┘        (rows retained on error; Retry refetches)

typesStatus:    idle ─► loading ─► ready            (error → tabs fall back to All only, status explains)

overlay:        none ─► control-center ─► none      (BCC opened/closed events)
                none ─► media-manager  ─► none      (MM opened/closed events)
```

## 5. Gallery states → wiring (D3/D4)

| State | What the schematic row exercises |
|---|---|
| S-03 | `describeTypes` in flight: `__search[disabled]`, `__types.is-skeleton[aria-busy]`, status "Loading object types…" |
| S-04 / S-05 | `search` in flight: skeleton rows + status, or `__load-more.is-loading[disabled]` with rows retained |
| S-06 / S-07 | `search` returned `items: []` for a type / for a query — `__empty` copy names the type / query; S-07 offers `clear-search` |
| S-08 | `search` rejected (5xx/network): status `.is-error`, `__error[role=alert]` + `retry`, rows retained |
| S-09a | `describeTypes` omitted a type (no `edit_*` cap) — nothing rendered for it |
| S-09b | `search` rejected 403 (`isRestRequestAuthorized` false): `__error--mode`, search disabled, refresh route |
| S-10 | `buildPostItem` with `hasFrontendRoute:false` → Edit-only rows (+ `__row-note` when status is `publish`/`term`) |
| S-11 / S-12 | `control-center:opened` / `media-manager:opened` → `is-inert` + `inert` + `aria-hidden`; `:closed` reverses |
| S-13 | `workspace:opened` → `--dbvc-ve-workspace-inset: 480px` → `clampPanelPosition` keeps the panel right of the drawer |
| S-15 | Real `:focus-visible` in production; roving tabindex on both tablists |
| S-19 | `localStorage` restore → `open({focus:false})` then `describeTypes` + `search(page 1, persisted activeType)` |

## 6. Not wired in R6-C (behaviour notes only)

- Persistence (`localStorage dbvc-ve-workspace:v1`), restore-on-load without focus steal.
- `inert` / `aria-hidden` toggling under BCC / MM.
- Panel clamp inset (`getPanelViewportBounds` reads `--dbvc-ve-workspace-inset`).
- Escape precedence (media modal › MM › BCC › toolbar popover › focused panel › workspace).
- Stale-response guard (`requestId`), debounce, focus continuity (`focusedItemKey`).
- Any write. The workspace never mutates content.
