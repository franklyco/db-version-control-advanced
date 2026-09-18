# R3 — Registry-Backed Brand Control Center wiring schematic

**Audience:** the UI/UX agent producing the R3 static mockup deliverable (index.html + states.html + mockup.js + styles.css + screenshots + notes), following the R1 Media Manager mockup precedent under `docs/ui-mockups/dbvc-visual-editor/r1-media-manager/`.

**Companion doc:** [CURRENT-VISUAL-EDITOR-COMPONENT-MAP.md](./CURRENT-VISUAL-EDITOR-COMPONENT-MAP.md) — the existing frontend surface the R3 UI must live alongside without visual/interaction drift.

**Do not read as authority when the code disagrees.** The code + `releases/R3-REGISTRY-BACKED-BRAND-CONTROL-CENTER.md` win.

---

## 0. Scope + non-goals (read first)

- **Desktop only, permanent (D-058).** No mobile/tablet layouts, no touch refinements, no real-handset optimization, no mobile-specific mockup screens. Preserve the existing narrow-width protections as a regression floor for unusual desktop DPI/zoom only.
- **Real AT (VoiceOver/JAWS/NVDA) is not a required gate.** Automated axe/keyboard/reduced-motion coverage in the mockup stays required (D-058).
- **R3 is deliberately minimal.** R3 is *not* the full Brand Control Center — R4 is. R3 ships:
  1. A **list of registered controls** (headless discovery — controls that live outside the current render).
  2. An **open action** per row that hands the row's opaque `publicId` to a fresh server-side descriptor resolution → existing panel opens for edit.
  3. Loading / empty / error / inspect-only / unsupported / unavailable states.
- **Out of scope for R3 (do not mock):** search-heavy expanded UI, category browsing beyond a single top-level filter, pinned controls, named workspaces, usage indexing, preview mode, bulk save/stage/undo, design-token editing, direct Bricks setting writes, any new custom DB tables. The richer expanded UI is R4.
- **No new write authority.** Opening a control routes through the existing `EditableRegistry`/`MediaFindingDescriptorBridge`-style pipeline; the registry is a **read-only discovery surface**. See §6 security.

---

## 1. End-to-end request flow

```text
[User clicks "Brand Controls" entry from the toolbar]
    │
    ▼
[Center opens] ─── mockup entry point; production reuses the existing popover/panel shell
    │
    ▼
GET /wp-json/dbvc/v1/visual-editor/control-center/controls          (R3-C, planned)
    │ ← permission_callback: canUseVisualEditor + edit-mode active + capability
    │ ← ControlRegistry::listControls({ category, status })
    │ ← per-user visibility closures run at list time
    ▼
[List renders]   ── one row per ControlRecord::toListItem() (see §3)
    │
    ▼
[User clicks a row's Open action]
    │
    ▼
POST /wp-json/dbvc/v1/visual-editor/control-center/open              (R3-C, planned)
    body: { publicId: "<providerId>:<localId>" }
    │ ← ControlRegistry::getVisibleRecord(publicId)  →  fails closed on unknown / stale / visibility-blocked
    │ ← Provider's own descriptor factory (registered alongside the provider) rebuilds an EditableDescriptor
    │   from the record's opaque `source` bag PLUS a fresh capability recheck.
    │ ← Response body carries the descriptor payload {input, family, expectedState} PLUS the
    │   safe descriptor summary (label, badgeLabel, source_context) the existing panel already
    │   consumes. No raw target (fieldKey, selector, ownerId, path) crosses.
    ▼
[Existing main editor panel opens] ── same panel used everywhere else in the Visual Editor;
                                       R3 does not add a new panel type.
    │
    ▼
[Save runs through the existing MutationService / journal / cache pipeline]
    │
    ▼
[Panel reconciles from the fresh reread]  ── same "save + no reload" pattern R2 uses
```

**What R3-A already ships:** `ControlProvider` interface + `ControlRecord` value object + `ControlRegistry` (list, register, dedupe, visibility filter, `getVisibleRecord`). See `addons/visual-editor/src/Registry/`.

**What R3-B ships next:** a `SharedGlobalsControlProvider` that adapts the existing `SharedGlobalFieldsController`'s configured relationship/post_object fields into `ControlRecord`s. Registers on `Addon::register()` under the MM-style feature gate. First real provider registered.

**What R3-C adds** (this is what the mockup must anticipate): the two REST routes above + the minimal center UI itself.

---

## 2. Provider ↔ registry contract (already implemented; R3-A)

`ControlProvider` is a two-method PHP interface:

```
public function getProviderId(): string    // sanitize_key; unique across providers
public function getControls(): array       // array of ControlRecord or loose arrays
```

Providers **do not** capability-filter (structural filtering only). The registry runs per-user visibility at list time so the same provider output can serve different users cheaply.

A `ControlRecord` carries (all sanitized on ingest via `ControlRecord::fromArray()`):

| Field | Type | Purpose | Whitelist |
|---|---|---|---|
| `id` | `sanitize_key` string | Provider-local id | non-empty |
| `providerId` | `sanitize_key` string | Provider that registered it | non-empty; namespace-forms `publicId = providerId + ':' + id` |
| `label` | sanitized text | Human-readable name shown in the list | non-empty |
| `category` | `sanitize_key` string | Category slug | any; defaults to `general` |
| `group` | sanitized text | Free-form subgroup inside the category | optional |
| `ownerType` | `sanitize_key` | Canonical resolver owner | `option \| post \| term \| user` → default `option` |
| `ownerSubtype` | `sanitize_key` | post type / taxonomy / options-page slug | optional |
| `fieldFamily` | `sanitize_key` | Field family for icon/badge hinting | `text \| image \| gallery \| relationship \| post_object \| other` → default `other` |
| `status` | `sanitize_key` | Discovery status | `available \| inspect_only \| unsupported \| unavailable` → default `unavailable` |
| `source` | array | **Internal only** — opaque provider hint used at open time to rebuild the descriptor. **Never** in the safe list projection. | provider-defined |
| `meta` | assoc scalar map | Sanitized icon/tooltip/badge hint the list UI can render | strings pass through `sanitize_text_field` |
| `visibleTo` | `Closure(): bool \| null` | Per-user visibility gate — runs at list time. Null → always visible. | closure or null |

**Unknown values default safely** so a misregistration cannot present a control as `available` by accident. Rejections (duplicate provider id, duplicate local id within a provider, malformed record) fire `do_action('dbvc_visual_editor_control_registry_invalid', $reason, $context)` for developer observability.

---

## 3. Data shape the mockup will receive

### 3.1 List route response (planned R3-C)

`GET /wp-json/dbvc/v1/visual-editor/control-center/controls?category=&status=`

```json
{
  "ok": true,
  "viewModelVersion": 1,
  "generation": "vecr_...",              // registry-level opaque generation (may be null in R3)
  "query": { "category": "", "status": "" },
  "categories": [                         // OPTIONAL in R3; may be omitted
    { "id": "general",  "label": "General",  "count": 4 },
    { "id": "branding", "label": "Branding", "count": 2 }
  ],
  "items": [
    {
      "publicId": "shared_globals:default_posts",
      "label": "Default Posts",
      "category": "globals",
      "group": "Shared",
      "ownerType": "option",
      "ownerSubtype": "acf_options",
      "fieldFamily": "relationship",
      "status": "available",
      "meta": {
        "badge": "Shared Global",
        "icon":  "collection"
      }
    }
  ]
}
```

**Do NOT expect these keys on list items** (the safe projection excludes them by contract):
`source`, `fieldKey`, `selector`, `ownerId`, `path`, `descriptor`, `token`, any raw ACF field key, any option name.

### 3.2 Open route response (planned R3-C)

`POST /wp-json/dbvc/v1/visual-editor/control-center/open` body `{ publicId }`

On success:

```json
{
  "ok": true,
  "publicId": "shared_globals:default_posts",
  "panel": {
    "label": "Default Posts",
    "badgeLabel": "Shared Global",
    "input": "reference_collection",
    "family": "acf_collection_field",
    "sourceContext": "toolbar_shared_global_option",
    "expectedState": { /* provider-shaped; opaque to the browser */ }
  }
}
```

The response's `panel` object is the **same shape** the existing Shared Globals popover already hands to the main panel — R3 changes *how the panel is opened* (from the center rather than from the Shared Globals popover), not *what the panel receives*.

Failure modes (mockup must show at least these):

| HTTP | Code | UI |
|---|---|---|
| 401 | `rest_forbidden` | Center closes with a "sign in required" toast — should not be reachable in the mockup |
| 403 | `control_center_mode_inactive` | Toast: "Turn on Visual Editor mode to use Brand Controls." |
| 404 | `control_unknown` | Row disappears; polite live-region announcement |
| 403 | `control_forbidden` | Row disappears; polite live-region announcement |
| 409 | `control_stale` | Toast + polite refresh prompt; row keeps its position |

---

## 4. State matrix the mockup must cover

| State | Trigger | UI shape | A11y |
|---|---|---|---|
| `loading-initial` | first open of the center | full-panel spinner + "Loading Brand Controls…" | `role="status"`, `aria-busy="true"` |
| `loading-refresh` | user changes category/status filter | list stays visible with a dimmed overlay + spinner | as above |
| `empty` | list returns `items: []` and no providers are registered | empty state with plain-language copy: "No global controls are registered yet." | static text |
| `empty-filtered` | list returns `items: []` under a category/status filter | empty state that names the filter and offers a "Clear filter" button | as above |
| `error` | list route returned an error | error state with plain-language copy + Retry | assertive live region |
| `list` | happy path | one row per item; row = `[status dot] label · category · group · badge · Open` | see §5 rows |
| `opening` | user clicked Open, awaiting open route response | that row swaps its Open control for `"Opening…"` + spinner; row itself does not disappear | polite live region + `aria-busy` on the row |
| `opened` | open route resolved → existing panel took over | center dims / hides while the panel is up; row keeps its position for when the panel closes | focus moves into the panel |
| `open-error` | open route returned 404/403/409 | inline error under the row + a Dismiss button; the row remains | polite (`404/403`) or assertive (`409`) live region |
| `inspect-only` | record `status: inspect_only` | Open control replaced by "View" (opens the panel read-only) | native button semantics |
| `unsupported` | record `status: unsupported` | row rendered muted, no Open control; tooltip explains why | text-only, not color-alone |
| `unavailable` | record `status: unavailable` (also the safe default for unknown status) | row rendered muted, no Open control; tooltip: "Not available in this build." | text-only, not color-alone |

Rows are **omitted from the DOM** when the record fails per-user visibility, not rendered disabled. Absent controls = omitted; disabled = never.

---

## 5. Row anatomy (mockup contract)

Each list row renders the SAME data shape regardless of provider, so a new provider registered later automatically renders correctly:

```
[status dot]  Label                                             [ Open ]
              category · group · badgeLabel                     [ View ]  (inspect-only)
              ownerType/ownerSubtype hint (small, muted text)   (none)    (unsupported/unavailable)
```

**Class names to use** (matches existing conventions — see companion component-map doc):

- Root: `.dbvc-ve-control-center` (BEM root, matches `.dbvc-ve-*` addon prefix)
- Row: `.dbvc-ve-control-center__row` + `is-<status>` modifier
- Status dot: `.dbvc-ve-control-center__status-dot` (color driven by CSS token, always with text label; never color-alone)
- Label: `.dbvc-ve-control-center__label`
- Metadata line: `.dbvc-ve-control-center__meta` (contains category · group · badge)
- Owner hint: `.dbvc-ve-control-center__owner`
- Open control: `.dbvc-ve-control-center__action` + `data-dbvc-ve-control-center-action="open"` + `data-public-id="<publicId>"`

The action `data-*` attributes match the pattern R2's Media Manager uses (`data-dbvc-ve-media-manager-action="assign-media"` + `data-finding-ref="..."`), so the same event-delegation approach applies.

---

## 5.1 Drawer shell contract (mockup contract — D-061)

The R3 center is a **left-anchored tabbed scrollable drawer**. It is a new
component; nothing on the frontend currently uses this pattern (the Media
Manager is a full-page modal; every other Visual Editor surface is a
popover or a floating panel). The drawer's job is to make dense curated
controls efficiently browseable.

### 5.1.1 Position + geometry

- **Anchor:** the LEFT edge of the viewport.
- **Animation:** slides in from the left on open; slides out on close;
  respects `prefers-reduced-motion: reduce` (component map §7).
- **Width:** fixed. **~480px** at both supported desktop viewports
  (1440×900 primary, 1280×720 secondary). Do not go below 420px (row
  legibility floor) or above 560px (leaves too little site behind).
- **Height:** full viewport height minus the WordPress admin bar (32px)
  and minus the bottom-anchored `.dbvc-ve-toolbar` strip (component map
  §2). Drawer top and bottom therefore align with the site content
  visible to the right of the drawer.
- **Z-index:** below the toolbar (`--dbvc-ve-z-toolbar`, 120020) and
  above the badges/status bar (`--dbvc-ve-z-panel`, 120010). Add a NEW
  token — do not hardcode. Suggested name `--dbvc-ve-z-drawer` = 120015.
  Add via a separate PR against `overlay.css` root, per component map §1.
- **Backdrop:** none. The site must remain visible behind the drawer
  (governing directives §7 — "keep the site visible behind the workspace
  where current layering permits it"). Drawer carries its own shadow /
  border to separate it visually from the site.
- **Coexists with the main editor panel.** The main editor panel opens
  on top of / beside the drawer when a row's Open button fires; drawer
  stays open in place. The two surfaces coexist — this is the "design-
  tool sidebar + inspector" pattern. When the panel closes, focus
  returns to the row that opened it (component map §10 — row-focus
  continuity).

### 5.1.2 Anatomy (top to bottom)

- **Header** (`.dbvc-ve-control-center__header`):
  - Title: "Brand Controls" (or maintainer's preferred label — designer
    proposes, maintainer approves).
  - Close button (`×`), Escape-key equivalent, `aria-label="Close Brand
    Control Center"`. Reuse the panel close-button styling from
    `.dbvc-ve-panel__header` (component map §4).
  - Optional: a small summary chip showing total controls + include count.
- **Tab strip** (`.dbvc-ve-control-center__tabs`):
  - One tab per curation category (`Brand`, `Contact`, `Content`,
    `Design`, `Layout Elements`, `Legal`, `SEO`) **plus** a first tab
    `All` that lists every category interleaved.
  - Designer may consolidate categories into 3–4 super-tabs if 8 tabs
    reads as cluttered at the 480px width. Tabs use native `<button>`
    with `role="tab"` semantics; `aria-selected` reflects state.
  - Tabs are horizontally scrollable inside the drawer if needed
    (`overflow-x: auto`), never wrap onto two lines.
- **Filter strip** (`.dbvc-ve-control-center__filters`) — persistent
  below the tabs, applies to whichever tab is active:
  - Free-text label search
  - Field-type filter (chips or select)
  - Status filter (chips: `available` / `inspect_only` / `unsupported` /
    `unavailable`)
  - Priority filter (from the curation JSON — `must` / `should` / `nice`)
  - "Clear filters" affordance visible only when filters are active
- **Table body** (`.dbvc-ve-control-center__table`) — the scrollable
  region:
  - `<table>` with semantic `<thead>` + `<tbody>`.
  - Sticky `<thead>` so headers stay visible while scrolling.
  - `<tbody>` scrolls internally; drawer body is the scroll container.
  - Row anatomy per §5 (unchanged): status dot, label, category/group/
    badge line, owner hint, and the row's Open control on the right.
  - Empty-tab state: "No controls in {Category}" with plain-language copy.
  - Empty-filter state: names the active filters + a "Clear filters"
    button (matches the R1 Media Manager empty-filtered pattern).
- **Footer strip** (`.dbvc-ve-control-center__footer`) — thin, optional:
  - Row count for the current view (e.g. "12 of 41 controls · 3 hidden
    by filters").

### 5.1.3 Interaction contract

- **Open:** clicking the new toolbar icon slides the drawer in from the
  left, moves focus to the first tab (or the search input if a filter
  was previously in use), and toggles the icon's `aria-expanded`.
- **Close:** clicking the close button, pressing Escape, or clicking the
  toolbar icon a second time — restores focus to the toolbar icon.
- **Tab change:** click OR arrow-key navigation on tabs; on change,
  moves focus into the new tab body (first row) and updates the URL
  hash (`#brand-controls/design`) so a mid-work reload restores the tab.
- **Row Open click:** POSTs the row's `publicId` to the open route (§1),
  main editor panel opens (over/beside the drawer). Drawer does NOT
  close. Row stays highlighted so the user knows where they came from.
- **Focus continuity:** on rerender (filter change, tab change back to
  same tab, retry after error), row focus is restored to the same
  `publicId` when possible (matches Media Manager's `activeElement`
  snapshot pattern — component map §10).
- **Outside click:** does NOT close the drawer. The drawer is a
  persistent workspace, not a transient popover. This is the ONE
  interaction that differs from popover conventions.

### 5.1.4 Style tokens

Reuse existing tokens (component map §7). Introduce **no new colors,
fonts, or z-index** in the mockup — token proposals go to a separate PR
against the guide. If the designer needs a new token, name it explicitly
in `DESIGN-DECISIONS.md` with rationale.

Required new token (already noted above): `--dbvc-ve-z-drawer` (120015).
Everything else must draw from the existing palette.

---

## 6. Security + stale-data invariants (must be honored by the mockup)

1. **`publicId` is the only client-authoritative token.** It is a namespaced key (`providerId:localId`), not a session token. It cannot be forged into pointing at a different record — the registry looks it up in-process and re-runs visibility for the **current** user on every resolution.
2. **No raw target in the DOM.** No `data-owner-id`, `data-field-key`, `data-selector`, `data-path`, `data-descriptor`, `data-token` attributes on rows. Only `data-public-id` + safe presentation attributes.
3. **Capability is checked twice** — once at list time (visibility closure) and again at open time (the provider's own descriptor factory + the underlying resolver). The mockup shows the failure copy for both.
4. **No client-supplied override.** The mockup must not accept an arbitrary `publicId` from user input — only the ids the current list handed the user are actionable. (Deep-linking is not R3.)
5. **Registry membership never grants edit permission.** An `available` status in the list means the current user *may* be able to edit; the authoritative check runs at open time. A row that opens with 403 is expected behavior for a stale visibility read.

---

## 6.1 Toolbar entry point + presentation form — decided (D-061, 2026-08-26)

**Supersedes D-060 (2026-08-24).** D-060 had proposed evolving the existing
Shared Globals (Layers) popover to hold the R3 list. The maintainer
reversed that direction on 2026-08-26 for two product reasons: (a) the
existing Shared Globals toolbar + popover is proven and must stay
completely intact — no evolution, no rows added, no visual change; (b) a
popover cannot deliver the tabular, filterable, dense UX the R3 center
needs at curation-realistic scale (30–60+ approved controls across 7
categories).

**Decision:**

- **A new dedicated icon** joins the Visual Editor floating toolbar
  (`.dbvc-ve-toolbar`) — one additional icon slot, no reshuffle of the
  existing slots (`pause/play`, `layers`, `search`, `image`, `globe`,
  `edit`, `power`). The `layers` slot continues to open the existing
  Shared Globals popover unchanged.
- The new icon opens the R3 center **as a tabbed scrollable drawer
  anchored to the LEFT edge of the viewport** — slides in from the left,
  overlays site content (site stays visible on the right per governing
  directives §7), fixed width, full viewport height minus the admin bar.
- The drawer is the primary R3 surface. It is **not** a popover, **not**
  a modal, **not** the Media Manager modal. It is a new component shell
  named `.dbvc-ve-control-center` (root of the drawer, matching the BEM
  prefix in §5).

**Consequences the mockup must honor:**

- **Do NOT touch the existing Layers / Shared Globals toolbar entry.**
  Do not mock changes to its icon, popover, contents, or trigger
  behavior. It remains in the toolbar exactly as it is today and its
  popover renders its current rows exactly as it does today.
- **Do add ONE new icon** to the floating toolbar. Icon suggestion:
  `sliders`, `dashboard`, or `layout`. The designer picks the final icon;
  reuse the existing `createToolbarButtonMarkup(action, iconName, label, extraClass, isSatellite)`
  helper (component map §2). Data-action attribute: `data-dbvc-ve-toolbar-action="open-control-center"`.
- **The drawer is a new component shell.** See §5.1 for the drawer
  contract (position, dimensions, tabs, filter strip, scrolling, focus
  and close behavior).
- **Rows still render the anatomy in §5.** The row anatomy is provider-
  agnostic and stays as documented; only the shell around them changes
  from "popover body" to "drawer tab body containing a table".

See [tracking/DECISION-LOG.md D-061](../tracking/DECISION-LOG.md) for
rationale + revisit trigger. D-060 remains in the log marked superseded.

---

## 7. Reuse boundary (do not invent new UI where existing UI covers it)

The R3 mockup **reuses** these existing surfaces:

- **Main editor panel** — same drag/close/viewport-fit panel every field opens into today. R3 does not add a new panel type.
- **Toolbar shell** — the existing `.dbvc-ve-toolbar` bottom-center strip. R3 adds ONE new icon into that strip via `createToolbarButtonMarkup(...)` (component map §2). The Layers / Shared Globals entry stays exactly as it is (D-061).
- **Notice / status regions** — the existing polite live region + toast pattern (component map §6).
- **Style tokens** — `--dbvc-ve-color-*`, `--dbvc-ve-font-*` (component map §7). No new colors or fonts.
- **Row-focus continuity pattern** — the Media Manager's `activeElement` snapshot approach for restoring focus across list rerenders (component map §10).

The R3 mockup **introduces**:

- **The left-anchored tabbed scrollable drawer** — root: `.dbvc-ve-control-center`. Full contract in §5.1.
- **One new toolbar icon** with `data-dbvc-ve-toolbar-action="open-control-center"`.
- **One new z-index token** — `--dbvc-ve-z-drawer` (120015) — added via a separate PR against `overlay.css` root, per component map §1.
- **Tab / filter-strip / table anatomy** classes under `.dbvc-ve-control-center__*` (see §5.1.2).

---

## 8. Deliverables the mockup should produce

Following the R1 Media Manager precedent, into a NEW directory
`docs/ui-mockups/dbvc-visual-editor/r3-brand-control-center/`:

- `index.html` — happy-path drawer at desktop viewport 1440×900, "All" tab active, drawer open, site content visible on the right
- `states.html` — every state from §4 side-by-side, labeled, PLUS the drawer-specific states:
  - drawer opening animation frame
  - drawer with each individual category tab active
  - drawer with filter chips active
  - drawer with sticky header while scrolled mid-table
  - drawer with a row's Open control mid-request (`opening`)
  - drawer + main editor panel coexisting (row opened → panel over/beside)
  - drawer with empty-tab state
  - drawer with empty-filtered state
  - drawer with all-statuses row (§4)
  - drawer with reduced-motion open (no slide animation)
- `mockup.js` — inert data-driven renderer using fixture JSON derived from `addons/visual-editor/curation/vertical-approved-controls.json` (see §9.1) with the additional synthesized rows for statuses / errors called out in §9.1
- `styles.css` — scoped under `.dbvc-ve-control-center`, uses existing tokens (see companion component-map §7). ONE new z-index token proposal (`--dbvc-ve-z-drawer` = 120015) is expected; document it in `DESIGN-DECISIONS.md`. No other new tokens.
- `screenshots/` — one PNG per state at 1440×900 and one at 1280×720. NO mobile screenshots (D-058). Include one PNG of the site + drawer together showing the drawer overlaying but not obscuring the site.
- `README.md` — quick tour + fixture description + which curation JSON snapshot was used (path + `exported_at` field)
- `DESIGN-DECISIONS.md` — why each state renders the way it was chosen; alternatives considered; token / class / icon choices; toolbar-icon choice + rationale; tab structure choice (per-category vs super-tabs) + rationale
- `COMPONENT-NOTES.md` — per-selector mapping (matches the R1 Media Manager `WIRING-SCHEMATIC.md` format)

**Explicitly not in the mockup deliverable** (matches R1 precedent):

- Real REST calls (the mockup is inert — `data-mockup-action="open"` posts nothing)
- The main editor panel itself (the mockup ends at the moment the row's Open control is clicked; the panel is reused as-is)
- Any real Bricks / ACF data
- Design tokens the mockup wants — token proposals go to a separate PR against the guide, not into the mockup

---

## 9. Fixture guidance (informs `mockup.js`)

### 9.1 Fixture source of truth (post-R3-BX — 2026-08-24)

Once the maintainer curates via the R3-BX **BCC Curation** admin tool, the
committed export at

```
addons/visual-editor/curation/vertical-approved-controls.json
```

becomes the **authoritative source of realistic mockup fixture data**. It's a
real snapshot of the approved options-page ACF fields on the reference
site (Vertical), with real labels, real category distributions, real
priorities, and real free-form notes — nothing invented. The mockup must
prefer this file over hand-authored placeholders wherever a fixture case
can be built from it.

**Payload schema:** [`addons/visual-editor/curation/README.md`](../../../../addons/visual-editor/curation/README.md).
Envelope carries `schema` (`dbvc.ve.curation.v1`), `exported_at`,
`source_site`, `counts`, `unlocks_summary`, `records[]`.

**How to map curation records → the list-route response the mockup renders
(§3.1):**

| Curation JSON field (`records[].`) | List item field (`items[].`) | Notes |
|---|---|---|
| — | `publicId` | Derive as `"shared_globals:" + records[].id` (or another provider prefix if the curated set is later split across providers). R3-BX exports one flat namespace. |
| `label` | `label` | Verbatim. |
| `category` | `category` | Verbatim (Brand / Contact / Content / Design / Layout Elements / Legal / SEO). |
| `group_title` | `group` | Verbatim. |
| `owner` | `ownerType` | Always `"option"` for R3-BX exports. |
| `owner_subtype` | `ownerSubtype` | Options-page slug. |
| `field_type` | `fieldFamily` | For families outside the `text \| image \| gallery \| relationship \| post_object \| other` whitelist (e.g. `color_picker`, `wysiwyg`), default to `"other"` for the mockup — the icon hint can still be driven from the raw `field_type`. |
| — | `status` | Assume `"available"` for every include record; the mockup fabricates one row each of `inspect_only`, `unsupported`, `unavailable` for the states matrix (§4). |
| — | `meta.badge` | `"Shared Global"` — matches the existing badge label. |
| — | `meta.icon` | Optional; derive from `field_type` if desired. |

**Fixtures the JSON alone does NOT cover:**

- `defer` and `ignore` rows (the export is include-only). The mockup can
  either omit them or synthesize a small sample for the states matrix —
  ignore rows should not appear in the center at all in production, so
  the mockup does not need to render them.
- `inspect_only` / `unsupported` / `unavailable` statuses — R3-BX exports
  only `include` records (which imply `available`). Fabricate the other
  three statuses for §4's states matrix.
- Empty-registry / loading / error transport states — mockup-only.

**If curation has NOT been exported yet:** the mockup falls back to the
hand-authored fixture set in §9.2 below. The designer should still ship
against real data whenever it's available — do not block the whole mockup
if it isn't.

### 9.2 Hand-authored fixture set (pre-export fallback)

Cover at least these fixtures so the mockup's states.html has honest data:

1. **Happy path** — 6 controls across 2 providers, 2 categories, mix of statuses (4 `available`, 1 `inspect_only`, 1 `unsupported`)
2. **Empty registry** — no providers registered
3. **Empty filtered** — happy path + category filter that matches nothing
4. **Loading initial** — items unset, loading flag true
5. **Loading refresh** — items present, loading flag true (dimmed overlay)
6. **Error** — items unset, error message present
7. **Opening a row** — happy path + one row's `opening` flag true
8. **Open error (403 stale visibility)** — inline error under the row
9. **All statuses** — one row of each: `available`, `inspect_only`, `unsupported`, `unavailable`

---

## 10. Sequencing signal for Codex / Claude Code (implementation agent)

After the mockup is accepted, implementation slices in the R3 release doc go: **R3-B** (Shared Globals compatibility provider registers first — no UI) → **R3-C** (production UI implementing the accepted mockup + the two REST routes from §1) → **R3-D** (hardening: capability + nonce + Bricks-Builder exclusion + browser coverage + release-notes/rollback). R3-A is already done.

The mockup does not gate R3-B (which is headless). R3-B can proceed in parallel with mockup work.
