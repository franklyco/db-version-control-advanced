# R4 mockup — design decisions

Every decision below has a rationale (what it does, why we chose it) and a rejected alternative (what we considered and why it lost). Read alongside `../../../docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/R4-EXPANDED-GLOBAL-BRAND-CONTROL-CENTER.md`; the release-doc section refs (§discoverability, §clarity, §interaction, §data-rules, §performance, §compatibility) anchor each choice to its acceptance criterion.

## Pinned before writing the mockup (2026-08-29)

Seven decisions were confirmed by the maintainer during planning. This section preserves the rationale so a future agent doesn't have to re-derive.

### 1. Search stays in the filter strip — persistent, above the row list

- **What**: The search input lives in the drawer's filter strip (between the tab strip and the row list). It is always visible when the drawer is open, no expand-to-search interaction.
- **Rationale**: Matches D-061's pinned drawer contract — the R3 drawer has search + tabs + chips visible simultaneously. R4 users at 400-row scale need search-first flow, so making the input persistent (rather than expand-on-click) is a two-key-press win per interaction. Release-doc §discoverability calls for "search registered controls by approved labels and descriptions" — a persistent bar makes that discoverable.
- **Rejected alternative**: Header-level expand-to-search (icon-only until clicked). Would break the D-061 drawer height budget by 44px whenever active and force the user to choose between search UI and tab UI at any given moment.
- **Release doc ref**: §discoverability #1.

### 2. Flat tab strip (All + up to 7 categories) — no left-sidebar tree

- **What**: The drawer's top-of-body category navigation is a horizontal tab strip identical in shape to R3 (`All`, `Brand`, `Contact`, `Content`, `Design`, `Layout Elements`, `Legal`, `SEO`). Overflowing tabs scroll horizontally inside the tab strip. No hierarchical / collapsible left sidebar.
- **Rationale**: Drawer is 480px fixed. A left-sidebar category tree eats ~120–140px, dropping row width to ~340px — value-summary chips + description + label become unreadable. Flat tabs preserve row density; grouping-within-category (decision #3) handles the sub-navigation need.
- **Rejected alternative**: Collapsible left sidebar with categories as a tree. Would need to solve horizontal-space contention at 1280×720 (where the drawer sits over the smallest usable site backdrop).
- **Release doc ref**: §discoverability #2.

### 3. Collapsible group headers within each category — ACF field-group grouping

- **What**: Within any category tab (including `All`), rows are grouped under collapsible section headers keyed on `record.group` (which comes from ACF field-group title, already emitted by both providers in R3-B / VerticalControlProvider). Group headers are **collapsed by default**; per-viewer preference persists via `localStorage`. Empty groups are omitted, not shown as "0 items".
- **Rationale**: The Vertical export at 400 records has ~40 distinct group titles per the R3-BX curation. Without grouping, a category tab like "Content" is a 60-row wall. With collapsible group headers, the drawer opens in a scan-friendly ~15-row summary, and drilling into a specific group is one click. Release-doc §clarity: "Every control shows authoritative source/owner context" — the group header IS that context, shown once per group instead of once per row.
- **Rejected alternative**: Flat list ignoring `group`. Loses at-a-glance ownership context; forces users to read the owner-hint line under every row.
- **Release doc ref**: §clarity #1, §discoverability #4.

### 4. Description as a muted second line under the label

- **What**: When `record.meta.description` is present, it renders as a muted, ~2-line-clamped line directly under the label. When absent, the layout doesn't reserve space (row height varies). No hover tooltips — the description is either visible in the row or not registered.
- **Rationale**: Descriptions are the release doc's mechanism for "controls should provide … short description when supplied" (§control records). Making them optional means providers opt in per record — the R3-BX curation JSON already carries `notes` per record which R4-A can map to `description`. Muted second line is the least-visually-disruptive way to show optional metadata; a tooltip would require hover discovery which fails clarity §2.
- **Rejected alternative 1**: Hover tooltip only. Fails discoverability — users don't know a description exists until they hover.
- **Rejected alternative 2**: Dedicated column. Would need to solve width contention at 1280×720 and forces every row into the widest schema.
- **Release doc ref**: §control records (short description when supplied).

### 5. Per-family value summaries as compact inline chips (right side)

- **What**: Each row's right edge (between the label/description block and the Open button) renders a **type-safe value summary chip** derived from `record.meta.currentValueSummary`. Per-family shapes:

  | Family | Chip shape |
  |---|---|
  | `text` (text/textarea/url/email/number/range) | truncated string (max 32 chars, ellipsis) + char count if truncated |
  | `image` | 24×24 rounded thumbnail (attachment ID resolved server-side) + short filename |
  | `gallery` | 3-thumb strip (24px each) + `+N` badge for overflow |
  | `relationship` / `post_object` | "N connected" text; first-3 titles rendered as tooltip content (hover-only, plain text, no HTML) |
  | `color_picker` | 12×12 swatch + hex code |
  | `wysiwyg` | stripped-text preview (max 40 chars, ellipsis) + word count |
  | `other` / unknown family | no summary chip, just the row label |
  | Empty value | "—" placeholder chip, muted |

- **Rationale**: Release doc explicitly calls for "safe current-value summary" per family (§control records) and forbids inline editing (§interaction model: "Do not create inline editing inside list rows in R4"). Compact chips give value context at scan-time without opening the panel; the panel remains the single edit surface. Right-side placement keeps the row's left half (label + description + owner hint) untouched from R3.
- **Rejected alternative**: Inline editable controls. Explicitly forbidden by release doc.
- **Data safety**: Summaries are resolved server-side and marked-up server-side; the frontend gets pre-escaped strings only. No raw option values, no unescaped HTML. Matches §data rules #1, #2.
- **Release doc ref**: §control records + §interaction model + §data rules.

### 6. Shared Globals folded into the same category system as Vertical records + view-mode toggle (decision #7 clarifies the toggle)

- **What (folded mode)**: In the default view, `SharedGlobalsControlProvider` and `VerticalControlProvider` (and any future provider) contribute rows into the same category tabs. Provider identity is preserved as a small badge on each row ("Shared Global" for R3-B, "Vertical" for Vertical, etc.). Category tabs count rows across all providers.
- **What (provider-partitioned mode — decision #7)**: An alternative view separates rows by provider. Top-level tabs become `All`, `Shared Globals`, `Vertical`, … one per registered provider. Within each provider tab, sub-grouping still uses `record.group` (collapsible group headers per decision #3).
- **Rationale for folding**: Release-doc §Shared Globals integration says the transition should be "decided from evidence whether Shared Globals becomes a compatibility route, subsection, alias, or legacy fallback." Folding is the strongest ownership statement — Shared Globals is just another provider contributing to a unified category system — and matches §discoverability's "categories driven by actual registered controls."
- **Why keep provider-partitioned mode too**: Some workflows benefit from provider grouping (e.g. auditing "what does Shared Globals actually surface here today?"). A per-viewer view-mode toggle is a cheap accommodation and lets R4-D evaluate real usage before committing to one shape.
- **Rejected alternative**: Two separate top-level sections hardcoded (Shared Globals vs Vertical), no toggle. Loses release-doc's "categories driven by actual registered controls" ambition and locks the drawer to today's two-provider reality.
- **Release doc ref**: §Shared Globals integration + §discoverability.

### 7. Category view-mode toggle in the drawer header — remembered per-viewer

- **What**: A small icon-based segmented control in the drawer header (immediately after the summary chip) lets the viewer flip between the two modes from decision #6. Two options: `By category` (default) and `By provider`. Preference persists via `localStorage` scoped to the drawer (not per-page or per-session).
- **Rationale (why a toggle at all)**: Confirmed by maintainer (2026-08-29). Both view modes have honest value; making it a per-viewer preference (a) avoids forcing an untested global preference, (b) lets R4-D measure actual usage before hardening one shape, and (c) fits the drawer's client-facing workspace stance without demanding a settings page.
- **Persistence choice**: `localStorage` (per-viewer, per-origin) — same treatment as decision #3's group-collapsed state. Not `sessionStorage` (would forget across tab reload) and not per-page (viewers work across many pages).
- **A11y**: Segmented control uses `role="tablist"` semantics with `aria-selected` on the active option, matching the tab strip's convention. Keyboard: arrow keys navigate between options, `Enter` / `Space` activates.
- **Rejected alternative**: Full-page settings toggle. Overkill for a preference this local; per-viewer localStorage is enough.
- **Release doc ref**: §Shared Globals integration ("decided from evidence") + §interaction (§interaction #2: "Focus moves predictably").

## Other design choices worth recording

### 8. Loading states inherit R3's shape

- **What**: `loading-initial` uses R3's full-panel spinner + copy. `loading-refresh` (during a category or search change) uses R3's dimmed-overlay pattern over the existing row list. `descriptor-loading` (a specific row's Open clicked) uses R3's per-row `is-opening` state with `aria-busy="true"`.
- **Rationale**: R3 already ships these three loading affordances and users familiar with the R3 drawer will recognize them at R4. Reinventing loading is out-of-scope.
- **Release doc ref**: §states, §performance.

### 9. Provider-error state is scoped to the affected provider

- **What**: When one provider's `getControls()` returns records the registry rejects (or the provider itself throws), the drawer renders a compact "Some controls could not be loaded — {providerId} reported an error" notice at the top of the row list. Other providers' rows continue to render normally.
- **Rationale**: Release-doc §data-rules "Provider/category failures should not expose implementation details to clients." Compact notice + provider id (opaque to end-user) satisfies that. Full-drawer error state would be over-triggering for a per-provider glitch.
- **Rejected alternative**: Full-drawer error state on any provider failure. Would take down the whole drawer for one buggy provider.
- **Release doc ref**: §data-rules #6, §states.

### 10. Search debounces + client-side by default; server-side flip is R4-A's decision

- **What**: In the mockup, search is presented as a debounced client-side filter (matches R3-C-2's pinned decision #3). Rationale line in `COMPONENT-NOTES.md` calls out that R4-A may add server-side `q=` for scale, but the mockup does not commit to that.
- **Rationale**: 400 rows is comfortably under the client-side scale ceiling proven by R3-C-2. Server-side flip is a future scaling concern R4-A owns.
- **Release doc ref**: §performance #1.

### 11. Value-summary chips are lazy-loaded when visible in viewport

- **What**: The mockup marks value-summary chip slots (`__value-summary-slot`) as empty for rows below the fold. Production R4-C wires `IntersectionObserver` to hydrate on scroll. The mockup itself pre-renders visible-viewport rows fully populated so the visual reference is accurate.
- **Rationale**: Release-doc §performance: "Keep descriptors and connected-item search lazy." Value summaries per family may involve extra DB lookups (attachment metadata, connected-post titles); lazy-loading them keeps the initial list route fast.
- **Rejected alternative**: Eagerly hydrate every row's value summary. Turns a 400-row list-route response into a 400-descriptor batch fetch.
- **Release doc ref**: §performance #4.

## Open questions handed to R4-A (backend)

R4-B does not commit to the backend shape; these are the design implications R4-A needs to resolve:

1. **ControlRecord new fields**: `description` (string, opt-in per record), `sortKey` (string, provider-defined for stable ordering), `currentValueSummary` (per-family opaque shape — image needs `{thumbUrl, filename}`, gallery needs `{thumbs[], count}`, etc.).
2. **List-route query params**: `q=` (search string), `category=` (slug or `all`), `status=` (slug or empty), `family=` (slug or empty). Debounce contract: 250ms.
3. **Per-family value-summary factory**: mirror of R3-C-1's `SharedGlobalsDescriptorFactory` extraction pattern but for the display-only value summaries. Runs on the list-route response; capability-checked per row to preserve visibility rules.
4. **Provider error surface**: providers currently can't declare a fatal error to the registry — they either return records or nothing. R4-A adds an optional error channel per provider that the drawer can render inline without taking down the whole list.

## Open questions handed to R4-C (frontend)

1. **Group-collapsed state persistence key**: `dbvc.ve.control-center.groups.<providerId>.<groupKey>` — should collapsed state be per-provider-group, per-category-group, or global?
2. **View-mode toggle a11y**: verify with real screen-reader QA whether segmented-control `role="tablist"` reads naturally when the surrounding drawer already has a `tablist` for categories. May need `aria-label` disambiguation.
3. **IntersectionObserver root**: should the value-summary lazy-load observer's root be the drawer body or the table wrap?

## Open questions handed to R4-D (Shared Globals transition + hardening)

1. **Long-label truncation**: at what character count does the label wrap vs truncate with ellipsis? R3-C-2 doesn't truncate; R4's inclusion of descriptions + value-summary chips leaves less room. Real large-registry QA at R4-D should measure.
2. **Provider-partitioned mode with 5+ providers**: today two providers (SharedGlobals, Vertical) fit in a horizontal tab strip. R4-D should measure at 5+ providers and consider dropdown collapse.
3. **Compatibility route decision**: measure real-usage preferences (folded vs provider-partitioned) via any lightweight telemetry the site already has; land a directional decision for R5+.
