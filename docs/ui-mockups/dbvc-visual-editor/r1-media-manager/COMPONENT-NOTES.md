# Component notes - R1 Media Manager mockup (final, D4A-3)

Naming: outer scope `.dbvc-ve-media-manager-mockup`, component prefix
`dbvc-ve-media-manager`, BEM-like elements/modifiers, `is-*` state classes, static JS hooks
`data-mockup-*` only.

## 1. Component inventory

| Component | Element | Bound to (fixture path) | Notes |
|---|---|---|---|
| `__header` | `<header>` | - | icon, title, subtitle, summary, close |
| `__summary-metric` x2 | `<dl>` group | `scan.summary.entitiesWithFindings`, `.totalFindings`, `.candidateEntitiesProcessed` | scan-wide, frozen against filters |
| `__close` | `<button>` | - | icon glyph + visually hidden name |
| `__scan-state` | `<p>` | `scan.state` | text + dot + color, never color alone |
| `__progress` | `<progress>` | `scan.progress.processed` / `.totalEstimate` | labelled, native element |
| `__scan-progress-text` | `<span>` | `progress.chunks`, `.attempts`, `.retryCount` | |
| `__scan-times` | `<ul>` of `<time>` | `startedAt`, `completedAt`, `expiresAt` | rendered UTC, machine-readable `datetime` |
| `__scan-actions` | `<div>` | `canRetry`, `canCancel`, `state` | see §3 |
| `__notice.is-info` | `<p>` | `summary.unsupportedFieldObservations`, `.invalidNonemptyValues` | aggregate only |
| `__notice.is-warning` | `<p>` | `scan.expiresAt` | snapshot freshness |
| `__search` | `<input type=search maxlength=100>` | `query.search` | 100-char cap from the query contract |
| `__chip-set` (entity) | radio group | `query.entityFamily` | `all` / `post` / `term` |
| `__chip-set` (field) | radio group | `query.fieldFamily` | `all` / `featured_image` / `acf_image` / `acf_gallery` |
| `__select` | `<select>` | `query.sort` | sole sort control, see §4 |
| `__scope-badge` | `<p>` | - | static, non-interactive |
| `__legend` | `<ul>` | `summary.featuredImageFindings`, `.acfImageFindings`, `.acfGalleryFindings` | plus status key |
| `__table` / `__row` | `<table>` / `<tr>` | `items[]` | `<th scope=row>` on the entity cell |
| `__row-toggle` | `<button>` | `availableActions.expand` | `aria-expanded` + `aria-controls` |
| `__missing-chip` | `<span>` | `missingCount` | singular/plural handled |
| `__family-chip` | `<span>` | `findingCounts.*` | zero counts omitted, not shown as "0" |
| `__open-link` | `<a target=_blank rel=noopener noreferrer>` | `entity.frontendUrl`, `availableActions.openFrontend` | omitted entirely when false |
| `__expansion` | `<tr>` + `<div>` | `expandedRows.*` | one open at a time; 200 payloads only |
| `__expansion-counts` | `<ul>` | `counts.missing/changed/resolvedOrChanged/unavailable` | |
| `__field` | `<li>` | `fields[]` | label, family, context, status, descriptor, message; present in all four 200 statuses |
| `__load-more` | `<button>` + note | `pagination.hasMore` | no page number, no total; omitted in `index.html` since `hasMore` is false, shown in `states.html` case 11 |
| `__sr-only` live region | `<p role=status aria-live=polite>` | derived | new; overlay.css has no such helper |
| `__footer-note` | `<footer>` | - | read-only release disclaimer |
| `__empty` | `<div hidden>` | - | markup present for translation, demonstrated in D3 |

## 2. Fixture reconciliation - resolved at D4A-1

D4 reported three discrepancies and left the fixture unmodified, as instructed. **Codex has since
adjudicated all three against `MediaScanReadModel` (D-030..D-032, E-040) and authorized the
fixture correction.** The fixture has been updated accordingly; this section records what changed
and why, so the reasoning is not lost.

**2.1 Sort order - corrected.** `query.sort` was `entity_asc` while `items[]` ran in `scannedAt`
descending order. Codex confirmed `entity_asc` is **alphabetical**, and that a scan-time ordering
cannot be presented with `entity_asc` or `aria-sort="ascending"`. The fixture items are now sorted
alphabetically - 10 Modern Garden Ideas, About Us, Home Page, Lake House..., Landscape Design,
Landscaping - and `index.html` renders that order, so the rendered order and `aria-sort` now agree.
The client still never re-sorts a server-ordered page.

**2.2 Pagination - corrected.** `pagination.hasMore` was `true` with a cursor, although all 6
entities and 13 findings fit under `limit: 20`. Codex confirmed a complete page must report
`hasMore: false` with an empty cursor. Both are now corrected, and `index.html` **omits** the Load
more control rather than disabling it. The paging affordance is still demonstrated, as a clearly
labelled illustrative partial page in `states.html` case 11.

**2.3 Freshness - corrected.** Home Page's `modifiedGmt` was about four hours *later* than its
`scannedAt` while the expansion still reported `current`. Codex confirmed expansion freshness
compares **per-finding empty fingerprints**, not modification time, so the two were never actually
in conflict - but the future-looking timestamp invited exactly the wrong inference. `modifiedGmt`
is now `2026-08-15 14:20:00`, at/before the scan time. The client still renders server status and
must never derive `current` from `modifiedGmt`.

**2.4 Expansion fields - corrected.** `expandedRows.changed`, `.resolved`, and `.unavailable`
carried no `fields[]`, which made abbreviated fixture states look like full production responses.
Codex confirmed all three include safe `fields[]` projections. Complete projections consistent with
`projectFinding()` have been added, using the server's own status/descriptor pairs and verbatim
messages:

| Status | Descriptor status | Fixture case |
|---|---|---|
| `missing` | `not_hydrated` | Home Page x3; About Us x1 |
| `changed` | `blocked_stale` | About Us x1 |
| `resolved_or_changed` | `unavailable` | Landscaping x1 |
| `unavailable` | `unavailable` | 10 Modern Garden Ideas x2, plus a retryable `error` object |

Field records are label-sorted then `findingRef`-sorted, matching the server's `usort`. About Us
keeps `newMissingFindingCount: 1` as a **count only**: the server builds `fields[]` from the
original snapshot findings, so a newly observed finding is never listed.

**These strings are synthetic presentation copy, not server vocabulary.** The field labels
"Team photo", "Archive image" and "Post gallery", their context labels ("About page content",
"Project type media", "Article media"), and the rendered descriptor phrases
("Descriptor: not hydrated", "Descriptor: blocked (stale evidence)", "Descriptor: unavailable")
were authored for the fixture and the mockup. They are **plausible display values, not literal
server output and not new backend vocabulary**. What *is* contract-exact is the shape: the
`projectFinding()` keys, the `status` / `descriptorStatus` pairs, and the four operator-facing
`message` strings, which are copied verbatim from `MediaScanReadModel`. Production must render
whatever labels the server actually returns; the descriptor phrases in particular are a UI
rendering of the `descriptorStatus` enum, not the enum itself.

Where the fixture supplies no revalidation payload - groups `vemg_cccc` (Landscape Design) and
`vemg_dddd` (Lake House) - the mockup renders an honest pending state and invents nothing. The
four groups that do have payloads render them in full.

**2.5 Sort keys - confirmed valid, no longer an open question.** Codex confirmed `missing_desc`
and `scanned_desc` are allowlisted, and that their ascending pairs are supported too. The
`<select>` options and the two `data-mockup-sortcol` attributes are correct as written.

## 3. State-gated controls in this view

`scan.state` is `complete`, `canCancel` false, `canRetry` false. Therefore:

- **Refresh scan** - rendered.
- **Continue** - omitted; no chunk remains.
- **Cancel** - omitted; `canCancel` false.
- **Retry** - omitted; `canRetry` false.

Omitted rather than disabled: a disabled control still advertises an authority the operator does
not have. HTML comments in `index.html` record why each is absent. D3's `states.html` shows all
four in the states where the fixture grants them.

## 4. Sorting - deviation from the concept image

The concept image shows a sortable "Updated" column header. `modifiedGmt` is **not** an
allowlisted sort key; the contract allows entity, missing, and scanned sorting only. So:

- The `<select>` is the single sort control. Headers are not clickable, which avoids advertising
  sorts that would be rejected.
- The active sort is conveyed with `aria-sort="ascending"` on the Entity header - valid on a
  non-interactive `th` and correct for assistive tech.
- **Resolved at D4A-1.** Codex confirmed `missing_desc` and `scanned_desc` are allowlisted sort
  keys and that their ascending pairs are supported too, so the `<select>` options and the two
  `data-mockup-sortcol` attributes are correct. No open question remains here.
- `entity_asc` is **alphabetical**, and as of D4A-1 the rendered row order matches it, so
  `aria-sort="ascending"` on the Entity header is now truthful. Before the fixture correction it
  was not.

## 5. Token strategy

Reused unchanged from `addons/visual-editor/assets/css/overlay.css`: the `--dbvc-ve-color-*`
palette, the warning/error/success/info semantic sets, `--dbvc-ve-color-focus`, the
`--dbvc-ve-font--*` and weight scale, `--dbvc-ve-border-radius--*`, `--dbvc-ve-box-shadow--panel`
and `--soft`, `--dbvc-ve-filter-backdrop--blur`, `--dbvc-ve-z-panel`, `--dbvc-ve-z-toolbar`.

New and scoped (`--dbvc-ve-media-manager-*`): `drawer-width`, `drawer-max-height`,
`drawer-inset-bottom`, `row-padding-y`, `cell-padding-x`, `section-padding-x`, `chip-radius`,
`chip-padding`, `table-border`, `sticky-shadow`, `focus-ring`, `toggle-size`.

Status colors map onto existing semantics and are always paired with text:
`missing` -> warning, `changed` -> info, `resolved_or_changed` -> success,
`unavailable` -> subtle, scan error -> error. Field-family chips additionally carry a leading
CSS glyph (diamond / circle / square) so they remain distinguishable without color.

**Mirrored-token decision.** `overlay.css` declares base tokens on `:root`, but this standalone
page may not add a global reset or unscoped selectors. The base `--dbvc-ve-*` values are
therefore mirrored **inside** `.dbvc-ve-media-manager-mockup` and annotated as a non-authoritative
copy. At production translation those mirrored declarations are dropped and the real `:root`
tokens are inherited. This is the one accepted duplication in the drop.

## 6. Structural decisions

- **Scope class on `<body>`, and a two-tier selector strategy.** Measured at D4: the stylesheet
  holds **245 selectors - 32 page-scoped under `.dbvc-ve-media-manager-mockup`, 213 carrying the
  `.dbvc-ve-media-manager` component namespace without that outer prefix**. Zero are bare element
  or universal selectors outside the scoped `.dbvc-ve-media-manager-mockup *` box-sizing rule, and
  zero fall outside the component namespace, so the capsule's "no global reset, no unscoped
  element selectors" rule holds.

  D3 described this as "every rule behind the scope selector with zero unscoped declarations."
  That was an overstatement and is corrected here. The split is deliberate and useful: the 32
  page-scoped rules are the throwaway standalone-page chrome (faux site bar, hero, faux toolbar,
  reopen button, `margin: 0`), and the 213 namespaced component rules port to production
  unchanged. At translation the page-scoped block is deleted outright rather than rewritten.
- **Scoped universal `box-sizing`.** `.dbvc-ve-media-manager-mockup *` - contained, not global.
- **`<section aria-labelledby>` rather than `role="dialog"`.** The drawer is non-modal; the live
  site stays visible and usable behind it. If production makes it modal, promote to `dialog` and
  add a focus trap.
- **Layout-pinned rather than `position: sticky` chrome.** The drawer is a flex column: header,
  scan band, notices, controls, and footer are `flex: 0 0 auto`; only `__results` scrolls
  (`overflow-y: auto` + `overscroll-behavior: contain`, matching `.dbvc-ve-panel`). The table
  `<thead>` is the one real `position: sticky` element. Rows carry `scroll-margin-block` so
  keyboard focus never lands under the sticky header.
- **Expansion as a sibling `<tr>`.** `<details>`/`<summary>` cannot span two table rows, so the
  toggle is a `<button>` with `aria-expanded` + `aria-controls`, matching the existing
  `.dbvc-ve-statusbar__toggle` convention. Every `aria-controls` target exists in the DOM;
  collapsed rows point at a `hidden` pre-fetch expansion rather than at nothing.

## 7. Responsive strategy (D3)

Three breakpoints. The 640px step is the only one `overlay.css` already ships; the 1024px step
is new and scoped to this component.

**D4A-2 replaced the scroll model on short and narrow viewports.** See section 9.

- **>1024px** - semantic table, sticky `<thead>`, fixed column widths via `<colgroup>` so the
  76-character entity label clamps instead of widening the Entity column.
- **641-1024px** - table retained. Chrome tightens: summary sub-notes and the entity-family
  sub-label are dropped, chip padding shrinks so the Field types column fits two chips per line,
  and the scan band stacks its timestamps onto their own row.
- **<=640px** - each row becomes a card in a two-column grid, with the entity and field-type
  cells spanning full width and the expand toggle pinned to the card's top-right corner.

**Card transform decision, changed from the D1 plan.** D1 proposed two parallel rendering
surfaces (`__table` plus a duplicate `__cards` list) swapped by CSS. That was rejected during
implementation: it doubles the markup a production template must emit and invites the two
copies to drift. Instead the single table is transformed with `display: block`, each cell
prints its column name from a `data-label` attribute, and **explicit ARIA roles**
(`role="table"`, `rowgroup`, `row`, `columnheader`, `rowheader`, `cell`) are present in the
markup at every breakpoint so the table semantics that `display: block` would otherwise strip
survive the transform. Column headers move out of the visual flow but stay in the
accessibility tree. No information is lost, and there is one source of truth.

**Two mobile layout bugs found and fixed by screenshot review:**

1. `.__control--search` and `.__control--sort` carry `flex: 1 1 220px` and `flex: 0 0 190px`
   for the desktop row. In the mobile column layout a flex-basis sizes the *block* axis, so
   those became 220px and 190px of dead vertical space. Both reset to `flex: 0 0 auto`.
2. `<fieldset>` carries UA-specific intrinsic sizing, so the chip groups are normalised with
   `display: block` to keep them behaving like ordinary blocks. The mobile chip row also wraps
   rather than forming a nowrap scroll rail - a rail hides filter options behind a swipe and
   needs its own affordance, while wrapping costs only vertical space.

## 8. Local interaction script (D3, optional)

`mockup.js` is local-only and deletable: without it the page still renders as a correct static
default view. It performs no network request, no persistence, and no mutation. It provides the
single-open row accordion, close/reopen, Escape-to-close, and a **simulation** of the query
round-trip (search, entity/field filter, sort, empty state, live-region announcements).

That simulation is explicitly not the production contract: production re-requests the page from
`getList()` and renders the server's order. The file says so in its header comment, and Load
more deliberately reports that only one fixture page is bundled rather than inventing a second.

## 9. Short-height and mobile scroll model (D4A-2)

**The defect.** The desktop model pins every band and makes only `__results` scroll. That holds
only while the non-result stack fits the drawer. Codex measured 390x844 with drawer client height
738 against scroll height 1264 and `overflow: hidden` - sort, legend, results and footer were
unreachable at any scroll position, because the one scroller was not the element overflowing
(RK-034).

**The fix.** Below 640px wide **or** 760px tall the drawer itself becomes the single vertical
scroll body: every band returns to normal flow (`flex: 0 0 auto`), `__results` stops scrolling
independently (`overflow: visible`), and the drawer takes `overflow-y: auto`. One scroll region,
one scrollbar, nothing clipped.

The height arm matters independently of width: a short desktop window clips the same way at 1440
wide. Above 640px in that mode the table header returns to normal flow, because a sticky `<thead>`
would now pin against the drawer and collide with the sticky header.

**Pinned chrome is the header only** - it carries the accessible name and the close control, so it
stays reachable from any scroll position. It needs an opaque background because the drawer surface
is translucent glass. The footer scrolls with the content.

**Touch targets** reach ~44px in that mode: close 44x44, row toggle 44x44 (via the existing
`--dbvc-ve-media-manager-toggle-size` variable), filter chips 44 tall, buttons/search/sort 44 tall,
and the front-end link 44 tall. Card padding-right grows to 60px so the 44px toggle clears the
card content. Focus styling is untouched, so `:focus-visible` rings still show.

**Two faux-chrome fixes were required to reach a true 320px viewport.** The mockup-only toolbar
dock laid out 344px wide as a non-wrapping fixed row, and the faux site nav 322px. Under mobile
emulation either one holds the layout viewport open, so a requested 320x568 was really rendering
at 338x600 - a 320px test that was never at 320. Both now wrap. Neither is production surface;
both are deleted at translation.

## 10. Known gaps after D4A-3

1. **Closed.** `DESIGN-DECISIONS.md` carries the decision record, candidate list, validation
   record, exact inventory, and limitations.
2. **Closed at D4A-1.** All three fixture discrepancies, the sort-key question, and the missing
   `fields[]` are adjudicated by Codex and corrected in the fixture. See section 2.
3. `__tbody` remains a styling-free BEM anchor for translation. (`__control--chips` carries the
   fieldset normalisation rule.) `__load-more` CSS is likewise retained while the control is
   absent from `index.html`; both are intentional translation anchors.
4. Capability, Media Manager feature flag, signed-mode, and snapshot-ownership gating are not
   simulated. The mockup assumes an already-authorized operator.
5. **Closed at D4A-1.** `missing_desc` and `scanned_desc` are confirmed allowlisted.
6. **Closed after D4.** The `__site-cta` overflow is fixed; geometry verified at 1440, 900, and
   390px with no horizontal overflow.
7. A correctly hidden `__thead` in card mode measures past the viewport edge under a naive
   `getBoundingClientRect` sweep. It is `position: absolute; clip-path: inset(50%);
   overflow: hidden` with a 1px box, paints nothing, and adds nothing to scroll width. Not a
   defect; recorded so it is not "fixed".

**Closed at D4A-2:**

8. **Short-height/mobile layout.** Codex measured, at 390x844, drawer client height 738 against
   scroll height 1264 with `overflow-y: hidden`, clipping sort, legend, results, and footer
   (RK-034, E-040). Replaced with a single reachable scroll body below 640px wide **or** 760px
   tall, pinning only the header. Verified at 1440x900, 1440x600, 900x1000, 390x844, 375x667 and
   320x568: all eleven probed targets reachable, no horizontal overflow, and both the footer and
   the last row visible at maximum scroll. See section 9.
9. **Interaction and copy corrections.** The announcement is now generated from the rendered
   panel, so a pending expansion announces "Requesting field detail. Still pending." and a
   populated one announces its status plus a field count read back off the DOM. A final result can
   no longer be announced while the panel shows pending. Singular/plural runs through one helper
   ("1 entity" / "6 entities"). Touch targets reach 44px for close, row toggle, filter chips,
   buttons, sort and the front-end link, with visible focus retained.

**Accessibility status after D4A-3 - partly proven, not signed off:**

10. **Automated passes now exist for both pages.** After the production subtle-text correction,
    axe-core 4.11.0 returned 33 passing rule groups and zero violations for `index.html`, plus 38
    incomplete contrast nodes; `states.html` returned 22 passing rule groups, zero violations,
    and zero incomplete results. Keyboard operation is separately verified with dispatched key events: tab order,
    Enter/Space activation, Escape close, reopen, and 14 of 14 stops showing a visible
    `:focus-visible` indicator.
11. **The confirmed contrast violation is resolved at its production source.** Codex changed
    `--dbvc-ve-color-text-subtle` from the 56% mix to the accepted 60% mix in production
    `overlay.css`, synchronized this mirror, reran both pages, and refreshed the screenshots. See
    `DESIGN-DECISIONS.md` section 6.8 for the original ratios and qualified follow-up.
12. **Still unproven.** Assistive-technology output (VoiceOver, NVDA), Safari and Firefox,
    real-device touch, and Lighthouse, which is not installed. Production integration is untested
    by definition: the mockup calls no route.
