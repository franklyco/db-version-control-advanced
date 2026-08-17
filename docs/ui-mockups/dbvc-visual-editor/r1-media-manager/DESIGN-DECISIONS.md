# Design decisions - R1 Media Manager static mockup (final, D4A-3)

Closing document for sub-phase **R1-D4**, updated through **D4A-3** after Codex's review. It records why the mockup looks and behaves the way it
does, which concept-image ideas were accepted, adapted, or deferred, what was actually validated,
the exact inventory, and the limitations that travel with the drop.

**This is a static mockup. It is not integrated with production.** No file here is loaded by the
plugin, imported by a build, or referenced by any PHP, JS, or CSS in `addons/visual-editor/`.
Nothing here calls a REST route. Codex performs production translation as separate work, and the
route/symbol mappings in `WIRING-SCHEMATIC.md` are design intentions to be reconciled against the
server, not verified bindings.

Release scope is **R1: read-only scan and report**.

**Review status.** Codex reviewed the D1-D4 drop and accepted it as *visual direction with
required adaptations* (D-030), not as production markup, CSS, JavaScript, fixture authority, or a
verified runtime contract. D4A-1 applied the contract, fixture, static-state, and schematic
corrections. D4A-2 applied the responsive scroll model, touch targets, announcement sequencing, and
singular/plural corrections. D4A-3 refreshed every screenshot against true viewport emulation, ran
the full validation sweep including a real axe pass, and finalised this document. Codex then signed
off D4A as qualified static visual direction, corrected the shared production subtle-text token,
synchronized this mirror, reran axe on both mockup pages, and began production translation with a
separate feature-gated toolbar/shell slice. **This directory remains non-production.**

---

## 1. Decision record

### 1.1 Mirrored base tokens - the one accepted duplication

`addons/visual-editor/assets/css/overlay.css` declares the `--dbvc-ve-*` base tokens on `:root`.
A standalone page in this drop may not add a global reset or unscoped selectors, so it cannot
rely on that `:root` block and must not recreate it globally.

Base token values are therefore mirrored **inside** `.dbvc-ve-media-manager-mockup` and annotated
in the stylesheet as a non-authoritative copy. At translation those mirrored declarations are
deleted and the real `:root` tokens are inherited. This is the only duplication accepted in the
drop; every other value is either a live reference to a base token or a new scoped one.

Measured: **52 distinct base `--dbvc-ve-*` tokens referenced, 12 new `--dbvc-ve-media-manager-*`
tokens added** (`cell-padding-x`, `chip-padding`, `chip-radius`, `drawer-inset-bottom`,
`drawer-max-height`, `drawer-width`, `focus-ring`, `row-padding-y`, `section-padding-x`,
`sticky-shadow`, `table-border`, `toggle-size`).

### 1.2 Scope class on `<body>`, and a two-tier selector strategy

The scope class sits on `<body>` so the standalone page needs no global reset. In production it
moves to a container element and the `margin: 0` page reset is dropped.

D3 described the result as "every rule behind the scope selector with zero unscoped
declarations." D4 measured it and that was an overstatement, corrected here and in
`COMPONENT-NOTES.md` section 6. The real shape is two tiers:

| Tier | Count | Example | Fate at translation |
|---|---|---|---|
| Page-scoped, under `.dbvc-ve-media-manager-mockup` | 38 | `__stage`, `__site-bar`, `__hero`, `__toolbar`, `__reopen` | deleted outright |
| Component-namespaced, `.dbvc-ve-media-manager*` | 228 | `__header`, `__row`, `__expansion`, `__field` | ported unchanged |

266 selectors total (re-counted after D4A-2). **Zero** bare element or universal selectors outside the scoped
`.dbvc-ve-media-manager-mockup *` box-sizing rule, and **zero** outside the component namespace,
so the capsule's constraint holds. The split is a feature rather than a compromise: the
throwaway page chrome is physically separable from the component, so translation is a deletion
plus a re-parent, not a rewrite.

### 1.3 Absent, not disabled

State-gated controls - Continue, Cancel, Retry - are omitted from the DOM rather than rendered
disabled. A greyed-out control still advertises an authority the operator does not have, and in
R1 several of these are gated by server state (`canCancel`, `canRetry`) that the client must not
second-guess. HTML comments in `index.html` record why each is absent in the default view;
`states.html` shows each one in the state where the fixture grants it.

### 1.4 One sort control

The concept image shows a sortable "Updated" column header. `modifiedGmt` is not an allowlisted
sort key - the contract permits entity, missing, and scanned sorting only - so a clickable
"Updated" header would advertise a sort the server would reject.

The `<select>` is therefore the single sort control. Headers are not interactive. The active sort
is conveyed with `aria-sort` on the matching `<th>`, which is valid on a non-interactive header
and correct for assistive technology, without implying the header is a button.

Codex confirmed at D4A-1 that `missing_desc` and `scanned_desc` **are** allowlisted, along with
their ascending pairs, so the three `<select>` options are correct. It also confirmed `entity_asc`
is alphabetical - which is why the fixture item order had to be corrected before
`aria-sort="ascending"` could honestly be claimed.

### 1.5 Scan-wide summary, frozen against filters

Header counts bind to `scan.summary` and are labelled "in last scan". They do not change when a
filter is applied. This is deliberate: a count that tracked the filter would be an exact filtered
total, which the product boundary forbids. The same reasoning removes "Page X of Y" in favour of
cursor paging, and keeps the empty state wordless about how many rows were excluded.

### 1.6 Non-modal `<section aria-labelledby>` rather than `role="dialog"`

The live site stays visible and usable behind the drawer, which is the point of a Visual Editor
surface - the operator is looking at the page the findings refer to. A modal dialog would fight
that. If production decides the drawer should be modal, promote it to `dialog`, add a focus trap,
and make the backdrop inert; the markup is structured so that is a contained change.

### 1.7a Two scroll models, selected by viewport (D4A-2)

Decision 1.7 below describes the desktop model and remains correct **only while the non-result
chrome fits**. It does not survive a short viewport, and that was a real defect rather than a
cosmetic one: at 390x844 the drawer measured 738px of client height against 1264px of content with
`overflow: hidden`, so sort, legend, results and footer could not be reached at any scroll
position. The single scroller was not the element that was overflowing.

Below 640px wide **or** 760px tall the drawer itself becomes the scroll body, every band returns to
normal flow, and `__results` stops scrolling independently. Only the header stays pinned, because
it carries the accessible name and the close control.

The height arm is deliberate. This is not a phone-only problem: a 1440x600 desktop window clips in
exactly the same way, so a width-only breakpoint would have fixed the symptom on handsets and left
it in place on laptops. Above 640px in that mode the sticky `<thead>` returns to static flow, since
the drawer is now the scroll container and two sticky layers would collide.

### 1.7 Layout-pinned chrome, single scroll region

The drawer is a flex column. Header, scan band, notices, controls, and footer are `flex: 0 0
auto`; only `__results` scrolls, with `overflow-y: auto` and `overscroll-behavior: contain` to
match the existing `.dbvc-ve-panel` convention. The table `<thead>` is the sole `position: sticky`
element, and rows carry `scroll-margin-block` so keyboard focus never lands underneath it.

One scroll region rather than several keeps the sticky header honest and avoids nested scroll
traps on touch.

### 1.8 Expansion as a sibling `<tr>`

`<details>`/`<summary>` cannot span two table rows, so the toggle is a `<button>` carrying
`aria-expanded` and `aria-controls`, matching the existing `.dbvc-ve-statusbar__toggle`
convention. Every `aria-controls` target exists in the DOM: collapsed rows point at a `hidden`
pre-fetch expansion rather than at a non-existent id, which would be an accessibility fault.

Only one row is open at a time, because each expansion is a fresh revalidation round-trip.

### 1.9 Card transform over duplicate markup - changed from the D1 plan

D1 proposed two parallel rendering surfaces, `__table` plus a duplicate `__cards` list, swapped
by CSS. That was rejected during D3: it doubles the markup a production template must emit and
invites the two copies to drift apart.

The single table is transformed instead. At 640px and below it becomes `display: block`, each
cell prints its column name from a `data-label` attribute, and **explicit ARIA roles**
(`table`, `rowgroup`, `row`, `columnheader`, `rowheader`, `cell`) are present in the markup at
every breakpoint so the semantics that `display: block` would otherwise strip survive the
transform. One source of truth, no information lost.

### 1.9a Announce what the DOM shows, not what was requested (D4A-2)

The local script previously announced "Field detail revalidated against current content" on every
expand, including rows whose panel showed only a pending placeholder - claiming a revalidated
result that was not on screen and might never arrive.

The announcement is now derived **from the rendered panel**: a panel carrying `is-pending`
announces that it is still pending, and a panel carrying records announces its status and reads
the field count back off the DOM. A final result therefore cannot be announced while the panel
still shows pending, because the sentence is generated from the same element the user is looking
at. Where the fixture supplies no payload, the row stays honestly pending rather than inventing
one.

Counts run through a single pluralisation helper, so "1 entity" and "6 entities" are both correct.

### 1.10 New scoped accessibility helpers

`overlay.css` ships no visually-hidden utility and `overlay-app.js` has no `aria-live` region, so
`__sr-only` and the polite live region are new. Both are worth carrying into production rather
than treating as mockup scaffolding: the live region is what makes expand, filter, sort, and
load-more perceivable to a screen reader user, and none of those actions move focus on their own.

### 1.11 Two real layout bugs found by screenshot review (D3)

Recorded because they are easy to reintroduce:

1. `__control--search` and `__control--sort` carried `flex: 1 1 220px` and `flex: 0 0 190px` for
   the desktop row. Once the controls became a mobile column, a flex-basis sizes the *block*
   axis, producing roughly 410px of dead vertical space. Both reset to `flex: 0 0 auto`.
2. `<fieldset>` carries UA-specific intrinsic sizing, so the chip groups are normalised with
   `display: block`. The mobile chip row wraps rather than forming a nowrap scroll rail; a rail
   hides filter options behind a swipe and needs its own affordance, while wrapping costs only
   vertical space.

### 1.12 A false diagnosis, corrected - and the part of the correction that was itself wrong

During D3 a mobile "horizontal overflow" was diagnosed twice from a cropped screenshot before
direct measurement showed the viewport was clamped to 500px and the PNG was merely cropped. The
guard rules added at the time are harmless and independently defensible, but the code comments
asserting the false cause were rewritten.

**D4 amends this.** The D3 conclusion that the overflow was entirely phantom was itself too
strong. There *is* a real horizontal overflow at narrow widths - it is simply not where D3
looked, and not in the component. See section 6.3. The durable lesson stands and is now confirmed
twice: **a PNG's pixel width is not the layout viewport.** Measure `innerWidth` and
`getBoundingClientRect`, never infer geometry from a screenshot.

---

## 2. Concept-image candidates: accepted, adapted, deferred

Consolidated from `WIRING-SCHEMATIC.md` section 4 and `COMPONENT-NOTES.md` section 2. Source:
`ui-ux/reference-images/04-media-manager-initial-concept.png`.

### 2.1 Accepted - carried through essentially as drawn

| Candidate | Notes |
|---|---|
| Large viewport-clamped drawer over a still-visible live site | Core layout premise; preserves Visual Editor toolbar continuity |
| Header with title, subtitle, and close | `__header` / `__close` |
| Scan status with progress and timestamps | `__scan-state`, `__progress`, `__scan-times`; native `<progress>` |
| Summary metric pair | Adapted in *meaning* - see 2.2 - but the visual treatment is as drawn |
| Search field | Capped at 100 characters per the query contract |
| Entity-family and field-family filter chips | Radio groups in `<fieldset>`/`<legend>` |
| Compact result rows with per-row expansion | `__row` + sibling `__expansion` |
| Per-row missing-count and field-family chips | Zero counts omitted rather than rendered as "0" |
| Open-front-end link | Honours `availableActions.openFrontend`; omitted entirely when false |
| Sticky column header over an internally scrolling result region | Single scroll region |
| Legend / status key | Text plus colour, never colour alone |
| Load-more paging affordance | Cursor-based only |

### 2.2 Adapted - kept in spirit, changed to fit the R1 boundary

| Candidate | As drawn | As built | Why |
|---|---|---|---|
| `36 Entities / 74 Missing Fields` | Filtered exact totals | Scan-wide `scan.summary` counts labelled "in last scan", frozen against filters | Exact filtered totals are forbidden; a scan-wide count cannot be misread as one |
| Sortable `Updated` header | Clickable column header | Non-interactive header; sorting only via `<select>` | `modifiedGmt` is not an allowlisted sort key |
| `Published Only` switch | Operator-togglable | Static `__scope-badge` | Scope is server-fixed in R1; a switch implies an authority the operator lacks |
| Sort affordance generally | Multiple clickable headers | One `<select>` plus `aria-sort` on the active header | Avoids advertising sorts the server would reject |
| Mobile presentation | Implied separate card design | Same table transformed to cards with explicit ARIA roles | One source of truth; see 1.9 |
| Field rows inside an expansion | Editable field list | Read-only records: label, family, context, status, descriptor status, safe message | R1 is read-only; no raw owner/field targets reach the DOM |

### 2.3 Deferred or rejected - deliberately absent from DOM and stylesheet

| Candidate | Why excluded | Earliest release |
|---|---|---|
| Row and select-all checkboxes | Selection exists only to drive save | R2 deferred |
| `Save selected (0)` | Cross-entity bulk save; not in initial R2 either | after R2 |
| `Save Row`, row and expansion | Field/row mutation | after R2 |
| `Choose Media`, `Upload New` | Media selection and upload | R2 deferred |
| `Manage Gallery`, `Add Images` | Gallery assignment | R2 deferred |
| Per-row `...` overflow menu | Only exposes unavailable actions | R2 deferred |
| Drag handles | Reordering; no backing data | not planned |
| `hero_image (ACF Image)`, `project_gallery (Gallery)` | Raw owner/field targets must never reach the DOM | never |
| `Listings` filter tab | Not an entity family; `Listing` is a `typeLabel` | n/a |
| `Page X of Y` | R1 pages by opaque cursor only | never |

Also not simulated: descriptor hydration (`availableActions.hydrateDescriptor` is false
throughout the fixture), rollback, arbitrary meta entry, and every write path.

A vocabulary scan over rendered content confirms none of the R2 strings above appear outside
documentation comments - see section 5.

---

## 3. Open questions - all resolved by Codex at D4A-1

D4 raised five items and deliberately left the fixture unmodified. **Codex adjudicated every one
of them against `MediaScanReadModel` and authorized the fixture correction** (decisions D-030,
D-031, D-032; evidence E-040). None remain open. The rulings:

| # | D4 question | Codex ruling | Applied |
|---|---|---|---|
| 1 | `query.sort` is `entity_asc` but items ran scan-time descending | `entity_asc` is **alphabetical**; a scan-time order may not be presented with `entity_asc` or `aria-sort="ascending"` | Items re-sorted alphabetically; rendered order and `aria-sort` now agree |
| 2 | `hasMore: true` although all rows fit under `limit: 20` | A complete page must report `hasMore: false` with an empty cursor | `hasMore: false`, `nextCursor: ""`; Load more omitted from `index.html` |
| 3 | `modifiedGmt` later than `scannedAt` yet status `current` | Freshness compares **per-finding empty fingerprints**, never modification time | `modifiedGmt` moved to `2026-08-15 14:20:00`; client still renders server status |
| 4 | `missing_desc` / `scanned_desc` unverified | Both are allowlisted; ascending pairs are supported too | Options and `data-mockup-sortcol` confirmed correct as written |
| 5 | Three expansion states carry no `fields[]` | All three **do** include safe `fields[]`; the abbreviated fixture states were not full responses | Complete projections added per `projectFinding()` |

### 3.1 What the corrected expansion projections contain

Built from the server's own vocabulary, with messages copied verbatim from `MediaScanReadModel`:

| Status | Descriptor status | Server message |
|---|---|---|
| `missing` | `not_hydrated` | "This supported media field is still empty. Descriptor hydration is deferred to the remediation phase." |
| `changed` | `blocked_stale` | "This field is still empty, but its scan evidence changed. Refresh the scan before taking further action." |
| `resolved_or_changed` | `unavailable` | "This field is no longer confirmed missing. Refresh the scan before taking further action." |
| `unavailable` | `unavailable` | "This field could not be revalidated safely. Refresh the scan after the provider is available." |

Field records are sorted by label then `findingRef`, matching the server's `usort`. Each carries
`availableActions` of `refreshScan: true`, `hydrateDescriptor: false`, `assignMedia: false`.

The provider-unavailable case adds a safe retryable `error` object using a real scanner code,
`media_scan_candidate_failed` / "A media scan candidate could not be inspected safely." /
`retryable: true`, matching `unavailableGroupResponse()`.

About Us keeps `newMissingFindingCount: 1` as a **count only**. The server builds `fields[]` from
the original snapshot findings, so a finding first observed after the snapshot is counted and never
listed. The mockup states this explicitly rather than leaving the discrepancy to be misread.

### 3.2 The distinction D4A-1 exists to make visible

`unavailable` means two entirely different things depending on where it appears, and conflating
them was the defect Codex flagged:

- **Row `status: unavailable` with `fields[]`** is a **200**. The group resolved, its fields came
  back, and the *rescan provider* failed. Render the fields plus the safe retryable error.
- **`404 media_scan_group_unavailable`** is a **request error** with no `row`, no `counts`, and no
  `fields[]`. This is the unpublished / deleted / out-of-scope / expired case. Render no counts -
  inventing one would misreport the entity.

`states.html` cases 9 and 10 now show these side by side, and `WIRING-SCHEMATIC.md` section 2.1
carries the full adapter table.

## 4. Exact inventory

Byte sizes measured after the post-D4A production-token synchronization and screenshot refresh. Sixteen delivered files plus this one.

| File | Bytes | Lines | Purpose |
|---|---:|---:|---|
| `index.html` | 49,993 | 796 | Default view, Home Page row expanded, responsive at all three breakpoints |
| `states.html` | 31,163 | 536 | 13-case state gallery |
| `styles.css` | 48,101 | 1,689 | Scoped stylesheet; mirrored base tokens plus 12 component tokens |
| `mockup.js` | 11,178 | 347 | Optional local-only interaction script |
| `README.md` | 10,682 | 173 | Entry document, status, viewing instructions, constraints |
| `COMPONENT-NOTES.md` | 21,169 | 308 | Component inventory, fixture reconciliation, token strategy, gaps |
| `WIRING-SCHEMATIC.md` | 22,388 | 295 | Selector map, state diagrams, not-wired list, D4 verification status |
| `DESIGN-DECISIONS.md` | this file | - | Decisions, candidates, validation, inventory, limitations |
| `CODEX-STATUS-UPDATE-HANDOFF.md` | 15,681 | 293 | Before/after text for the roadmap, phases, and tracker updates; paste-ready Codex prompt |
| `D4-HANDOFF.md` | 14,558 | 248 | Session aid from the D3-to-D4 handover; not part of the output contract |
| `01-desktop-1440x900.png` | 594,256 | - | 1440x900 measured; table layout, results region scrolls |
| `02-desktop-short-1440x600.png` | 367,791 | - | 1440x600 measured; height arm active, drawer is the scroll body |
| `03-tablet-900x1000.png` | 494,871 | - | 900x1000 measured; tightened table |
| `04-mobile-390x844-top.png` | 208,671 | - | 390x844 measured; card layout at top of scroll |
| `05-mobile-390x844-bottom.png` | 174,317 | - | 390x844 measured; maximum scroll, last row + footer visible |
| `06-mobile-320x568.png` | 140,590 | - | 320x568 measured; narrowest supported viewport |
| `07-states-gallery-1280.png` | 850,325 | - | 1280 wide, 4016 tall full page; all 13 gallery cases |

Total delivered payload excluding this file and the screenshots: **224,913 bytes** across 9 text
files. The seven screenshots add 2,830,821 bytes.

`D4-HANDOFF.md` is not in the capsule's output contract. It was kept rather than deleted because
it is the only record of the D1-D3 session history; the capsule permits either choice.

---

## 5. Static validation record

No project test runner covers a static mockup, and none was added. Everything below was **run at
D4**, not carried over from D3. Validation level: targeted structural and constraint checking,
appropriate to a non-executing documentation artifact.

### 5.1 What was run and passed

A throwaway Python script using only the standard library's `html.parser` - written ad hoc in the
scratchpad, **not kept as a deliverable**, consistent with D3.

**30 of 30 checks passed.** The suite has been run three times: at handback, after the section 6.3
stylesheet fix, and again after the D4A-1 contract/fixture corrections. All three runs passed
30/30. The second run was accompanied by geometry measurements at 1440, 900, and 390px confirming
no horizontal overflow at any width.

### 5.0a D4A-3 final validation sweep

Everything below was run at D4A-3 against the current files.

| Check | Result |
|---|---|
| `node --check mockup.js` | pass |
| Structural / ARIA suite | pass 30/30 |
| Unique element ids | pass |
| `aria-controls` / `aria-labelledby` / `aria-describedby` resolve | pass |
| `label[for]` targets resolve | pass |
| `git diff --check` | pass |
| `shasum -a 256 -c PACKAGE-CONTENTS.sha256` | pass, 46/46 |
| All 46 manifest entries vs disk bytes + SHA-256 | pass, 0 mismatches |
| Rendered order alphabetical and `aria-sort` agrees | pass |
| Load more absent from the default response | pass |
| One expanded row at a time | pass |
| Completed vs pending live-region announcements | pass |
| Singular / plural copy | pass, "1 entity" and "6 entities" |
| Geometry + reachability at 6 viewports | pass, see below |
| No horizontal overflow at each viewport | pass, all six |
| Footer and last row reachable at mobile maximum scroll | pass, all six |
| 44px mobile touch-target boxes | pass |
| Keyboard operation | pass, see 5.0c |
| axe-core 4.11.0, WCAG 2.0/2.1 A + AA | post-token rerun: **0 violations** on both pages; see 6.8-6.9 |

### 5.0b Viewport geometry

Each row is a measured layout viewport, not a requested one; requested and measured agreed in all
six cases.

| Viewport | Scroll body | `__results` | Header | Drawer client/scroll | h-overflow | Reach | Footer + last row at max scroll |
|---|---|---|---|---|---|---|---|
| 1440x900 | `__results` 293/891 | `auto` | static | 774/1284 | none | 11/11 | both visible |
| 1440x600 | drawer | `visible` | sticky | 474/1433 | none | 11/11 | both visible |
| 900x1000 | `__results` 365/924 | `auto` | static | 874/1349 | none | 11/11 | both visible |
| 390x844 | drawer | `visible` | sticky 136 | 706/3614 | none | 11/11 | both visible |
| 375x667 | drawer | `visible` | sticky 136 | 529/3708 | none | 11/11 | both visible |
| 320x568 | drawer | `visible` | sticky 154 | 430/3903 | none | 11/11 | both visible |

Touch targets on the four short/narrow viewports: close 44x44, row toggle 44x44, chip 44 tall,
front-end link 44 tall, button 44 tall, sort 44 tall. Desktop retains its denser 30/26/32 sizing.

### 5.0c Keyboard operation

Driven with dispatched key events, not simulated clicks:

- **Tab traversal** reaches close, refresh, search, both filter groups, sort, every row toggle and
  every front-end link in a logical order. 14 of 14 stops matched `:focus-visible` and 14 of 14
  showed a visible indicator (2px solid outline, or the UA ring on radio inputs).
- **Enter** on a row toggle expands and announces; **Space** collapses; `aria-expanded` tracks both.
- **Escape** closes the drawer and moves focus to the reopen control; **Enter** reopens it and
  announces "Media Manager reopened."
- Expanding a second row collapses the first - one open row at a time held throughout.

One correction worth recording: an earlier run appeared to show Enter not activating the toggle.
That was a harness fault - `rawKeyDown` does not make Chrome synthesise the click that `keyDown`
with text does. Re-run correctly, Enter works. No page change was needed, and none was made.

### 5.0 D4A-1 additions

Run after the fixture and static-state corrections:

| Check | Result |
|---|---|
| `jq empty` on the corrected fixture | pass - valid JSON |
| `jq empty` on `PACKAGE-MANIFEST.json` | pass - valid JSON |
| Structural/constraint suite on changed HTML | pass - 30/30 |
| Unique ids and all ARIA references resolve | pass - re-verified after the row reorder |
| Rendered row order agrees with `entity_asc` and `aria-sort` | pass - 10 Modern Garden Ideas, About Us, Home Page, Lake House..., Landscape Design, Landscaping |
| Default Load more absent when `hasMore` is false | pass - omitted from the DOM, not disabled |
| `node --check mockup.js` | pass |
| `git diff --check` | pass |
| `shasum -a 256 -c PACKAGE-CONTENTS.sha256` | pass - 46 of 46 OK |
| All 46 manifest entries match disk bytes and SHA-256 | pass - 0 mismatches |
| Fixture field records match `projectFinding()` shape, vocabulary, and sort | pass - verified against the read model source |

**Not claimed by D4A-1:** accessibility, assistive-technology, cross-browser, real-device, or
production-integration validation. None were run, and D4A-1 changed nothing that would affect
them.

| Check | Result |
|---|---|
| Tag balance, both HTML files | pass |
| Unique element ids | pass - 17 in `index.html`, 6 in `states.html` |
| `aria-controls` targets resolve | pass - 6 references, all resolving |
| `aria-labelledby` / `aria-describedby` resolve | pass - 1 and 0 references |
| `label[for]` targets resolve | pass - 10 and 6 references |
| ARIA role census against the table contract | pass - `table` 1, `rowgroup` 2, `row` 13, `columnheader` 8, `rowheader` 6, `cell` 48, `status` 1 |
| `<colgroup>` / header / colspan agreement | pass - 8 `<col>`, 8 `th[scope="col"]`, single `colspan="8"` |
| `data-label` on every body cell | pass - toggle cell exempt, see 5.3 |
| No inline event handlers | pass |
| No external subresource | pass - only relative `styles.css` and `mockup.js` |
| No `@import`, webfont, or `url(http…)` | pass |
| No bare element or universal selector outside page scope | pass - 0 found |
| Every selector inside the component namespace | pass - 245 of 245 |
| No `:root` declaration in the scoped stylesheet | pass |
| `node --check mockup.js` | pass - Node v22.23.1 |
| Banned browser APIs absent from executable JS | pass - see 5.2 |
| R2 vocabulary absent from rendered content | pass |
| `git diff --check` | pass - no whitespace errors |

### 5.2 Banned-API check

Comments are stripped before grepping, because `mockup.js`'s own header lists these names as
prohibitions and trips a naive search. Confirmed absent from executable code: `fetch`,
`XMLHttpRequest`, `localStorage`, `sessionStorage`, `document.cookie`, `indexedDB`, `sendBeacon`,
`eval`, `innerHTML`, `WebSocket`, dynamic `import()`, and `new Function`.

The five `<a href>` values in the page point only at `https://example.test`, an RFC 6761 reserved
domain that cannot resolve. All five match the fixture's `entity.frontendUrl` values exactly; the
sixth entity has an empty `frontendUrl` and correctly renders no link.

### 5.3 Two initial failures that were checker faults, not page faults

Recorded so the next reviewer does not re-derive them:

- **"External href" on five `<a>` elements.** Navigational links are not subresource loads; the
  page issues no network request on load. The check was narrowed to `src`/`link[href]` and a
  separate assertion added that anchors target only `example.test`.
- **"`<td>` missing `data-label`" on six toggle cells.** `__cell--toggle` pairs with a
  `__th--toggle` header that has no visible name, so printing a column label in card mode would
  emit noise. Exempted deliberately.

### 5.4 What was NOT run, and the residual risk

**Axe runs now exist for both pages.** The original D4A-3 run against `index.html` returned 33
passing rule groups and one serious 12-node `color-contrast` violation inherited from the shared
production subtle-text token. Codex then corrected the authoritative token, synchronized this
mirror, and reran axe-core 4.11.0 for `wcag2a`, `wcag2aa`, `wcag21a`, and `wcag21aa`:
`index.html` returned 33 passing rule groups, zero violations, and 38 contrast nodes as
*incomplete*; `states.html` returned 22 passing rule groups, zero violations, and zero incomplete
results. Sections 6.8-6.9 preserve the original finding and the qualified follow-up.

What remains genuinely not run:

| Not run | Residual risk |
|---|---|
| Lighthouse | Not installed, and installing was out of scope. No performance, SEO, or best-practices audit exists. |
| Real assistive-technology testing (VoiceOver, NVDA) | Roles, names, and the live region are structurally correct, and keyboard operation is verified with dispatched key events, but nothing was *heard*. Announcement wording remains unproven. |
| Cross-browser rendering - only Chrome was available | Safari and Firefox untested. `clip-path`, `overscroll-behavior`, sticky `<thead>` inside a transformed table, and `<fieldset>` normalisation are the likeliest divergence points. |
| Real handset testing | Emulation only. Touch-target sizing is untested on hardware. |
| Any PHP, REST, or integration test | Correct - the mockup touches no production code. Nothing here can be integration-tested. |
| CSS validation against a formal grammar | The scoping analysis uses a hand-rolled brace parser, adequate for scoping but not a substitute for a real CSS validator. |

Consistent with `addons/visual-editor/AGENTS.md`, this is risk-based validation at the level the
artifact warrants: a non-executing, non-integrated documentation asset. The automated
accessibility, structural, geometry and keyboard evidence above is real; the assistive-technology,
cross-browser, real-device and integration gates remain production QA obligations and are not
claimed here.

---

## 6. Limitations

### 6.1 Not simulated

Capability checks, the Media Manager feature flag, signed mode, WordPress REST authentication,
and current user/site snapshot-ownership gating. **The mockup assumes an already-authorized
operator.** Every route in `WIRING-SCHEMATIC.md` requires all five in production, and explicit
scan actions additionally carry the current generation and expected revision. None of that is
representable in a static page, and none of it should be inferred as designed-away.

### 6.2 Screenshot capture path - resolved at D4A-3

All seven screenshots are now captured over the DevTools Protocol with
`Emulation.setDeviceMetricsOverride`, and each records its requested size, its **measured** layout
viewport, and its capture dimensions. Requested and measured agree in every case, including a true
320x568.

The retired `--window-size` path is documented here because its failure mode is silent: it clamps
to a 500px minimum and then *crops* the PNG to the width requested, so the output width is not the
layout viewport. A "375px" capture from that path was really a 500px render. Two mockup-only
elements - the faux toolbar dock and the faux site nav - also had to be made to wrap before a
requested 320 stopped being emulated as 338.

### 6.2a Historical note - the original clamp diagnosis

The `--window-size` capture path is clamped and misleading, and this is worth knowing before
trusting any screenshot from it.

1. **The clamp is real.** Requesting `--window-size=375,1400` produces a 375px-wide PNG whose
   measured layout viewport is **500x1313**. Chrome renders at 500 and crops the image. Anyone
   trusting the PNG dimension would believe they had a phone-width capture. Screenshots 01-04
   use this path, which is why `03-mobile-500w.png` is 500px rather than phone-width.
2. **CDP emulation bypasses the clamp.** `Emulation.setDeviceMetricsOverride` at 390px takes
   effect properly. The first attempt still produced a 601px capture because of the overflow in
   6.3; once that was fixed, the same call measures `viewport 390x1500, scrollWidth 390`.

`05-mobile-390w.png` is the resulting genuine handset capture. `03-mobile-500w.png` is retained
rather than replaced: it is a valid card-layout render, and the pair demonstrates the breakpoint
holding across a 110px width difference.

### 6.3 Resolved: `__site-cta` overflow at narrow widths

**Status: found and measured during D4, fixed immediately afterwards on explicit instruction to
continue.** Recorded in full because the diagnosis is the useful part.

At a 500px viewport, `document.scrollWidth` was 601. A full element sweep attributed this to
exactly one node: `.dbvc-ve-media-manager-mockup__site-cta` at `left=525, right=601`, inside the
`mockup-only` faux site bar, which is `display: flex` with `overflow: visible` and did not wrap.
Under mobile emulation the overflow expanded the visual viewport, which is what defeated the
phone-width capture.

**The Media Manager component never overflowed at any tested width** - drawer 488 inside 500,
results region 486. The defect was confined to the faux page chrome that exists only to place a
"live site" behind the drawer.

The fix adds `flex-wrap: wrap` and a wrapped-row height/padding to `__site-bar`, and hides
`__site-cta`, entirely within the existing 640px breakpoint. Both selectors are page-scoped, so
the change sits in the tier-1 throwaway chrome from section 1.2 and **cannot reach the production
component**. A stylesheet comment records the measured cause so the rule is not later mistaken
for arbitrary styling and reverted.

Verified after the change at three widths:

| Viewport | `scrollWidth` | Table mode | Drawer |
|---|---|---|---|
| 1440 | 1440 | `table` | 1120 |
| 900 | 900 | `table` | 868 |
| 390 | 390 | `block` (cards) | 378 |

No horizontal overflow at any width. The 30-check structural suite was re-run after the change
and still passes 30/30.

### 6.4 A correctly hidden header row that looks like a defect

Two `<tr>`/`<th>` nodes measure past the viewport edge in card mode. This is **not** a bug. They
sit inside `__thead`, which is `position: absolute; clip-path: inset(50%); overflow: hidden` with
a 1px box - the standard visually-hidden pattern. A naive `getBoundingClientRect` sweep reports
descendants of a clipped container at their unclipped size; they paint nothing and contribute
nothing to document scroll width. Recorded so nobody "fixes" it.

### 6.5 The local script is a simulation, not a contract

`mockup.js` simulates the query round-trip - search, filter, sort, empty state, live-region
announcements - by manipulating rows already in the DOM. **Production does none of this.** It
re-requests the page from `getList()` and renders the server's order; it never re-sorts or
re-filters a delivered page client-side. The file says so in its header, and Load more reports
that only one fixture page is bundled rather than inventing a second.

Delete `mockup.js` and the page still renders correctly as a static default view.

### 6.6 Dangling BEM anchor

`__tbody` is declared in markup but carries no CSS rule. It is an intentional translation anchor.
Note it; do not delete it.

### 6.7 Repository files intentionally not updated

Nothing outside this output directory was touched. The capsule scopes writes here and forbids
editing package tracking, manifests, and checksums. Three separate questions were checked rather
than assumed:

**Superseded at D4A-1 for the fixture only.** Codex explicitly lifted the package-write
prohibition for the existing R1-C fixture and its existing manifest/checksum records. Because the
fixture changed, its `bytes` and `sha256` entry in `PACKAGE-MANIFEST.json` and its single line in
`PACKAGE-CONTENTS.sha256` were updated in place. No entry was added, no entry was removed, and the
package version stays at 2.4.2. Everything below still holds for the **mockup files**, which
remain outside the package.

**Mockup files still need no manifest entry - this was verified, and an earlier note in this
document claiming a gap was wrong.** `PACKAGE-MANIFEST.json` declares
`target_location: DBVC/docs/dropins/dbvc-visual-editor-brand-controls-guide/` and every one of its
46 file entries is package-relative; neither it nor `PACKAGE-CONTENTS.sha256` contains a single
reference to `ui-mockups`. This mockup lives at `docs/ui-mockups/dbvc-visual-editor/r1-media-manager/`,
outside the package, so it is **out of manifest scope by design**. The R1-C fixture, which *is*
inside the package, is already listed in both files with a checksum. **Codex should not add
mockup entries to either file.**

Codex reconciled the canonical roadmap, phases, release, decision, evidence, risk, and
implementation-tracker records after D4A sign-off. `CODEX-STATUS-UPDATE-HANDOFF.md` is retained
only as superseded provenance.

---

### 6.8 RESOLVED confirmed violation: shared subtle-text token

**Found by a real axe run at D4A-3, not by inspection. Initially left open here so the mockup could
not conceal a production defect. Corrected at the production source by Codex on 2026-08-16.**

axe-core 4.11.0 against WCAG 2.0/2.1 A and AA returned **one violation**: `color-contrast`,
impact *serious*, **12 nodes**. Every node has the same foreground, `#70768e`, and every measured
ratio is just under the 4.5:1 threshold:

| Background | Where | Ratio | Needs |
|---|---|---|---|
| `#ffffff` | column headers, field context lines | 4.49 | 4.5 |
| `#f5f7ff` | legend chips, expansion notes | 4.20 | 4.5 |
| `#f0f3ff` | expanded-row family note, expansion header | 4.06 | 4.5 |

`#70768e` was `--dbvc-ve-color-text-subtle`, declared as
`color-mix(in srgb, var(--dbvc-ve-color-dark) 56%, #ffffff)`. The authoritative declaration lives
in production `addons/visual-editor/assets/css/overlay.css`; the mockup only mirrors it. Sixteen
mockup rules consume the token; none of them chose the colour.

This was an **inherited palette defect, not a mockup-only defect**. D4A correctly did not change
the mirror by itself. Codex later moved the production mix from 56% to **60%** (`#666c86`) and only
then synchronized the mockup mirror, preserving the authority contract in section 1.1.

The accepted 60% mix clears 4.5:1 on all three reviewed backgrounds, worst case 4.68. 58% would
still fail at 4.36 on `#f0f3ff`.

The post-correction default-view rerun reports zero confirmed violations. It still returns 38
contrast nodes as *incomplete*, so the result does not establish full contrast coverage for every
computed state or production consumer.

### 6.9 What the axe pass does and does not prove

The post-correction runs covered `wcag2a`, `wcag2aa`, `wcag21a` and `wcag21aa`. The default view
returned **33 passing rule groups and zero violations**; the state gallery returned **22 passing
rule groups and zero violations**. The default view retained 38 incomplete contrast evaluations.

It is still not an accessibility sign-off. Automated rules catch a minority of real barriers.
Untested: screen-reader output and announcement wording with actual assistive technology, Safari
and Firefox behaviour, real-device touch, and magnification/reflow beyond the measured viewports.

## 7. Product boundary - re-asserted at handback

**R1 is read-only scan and report.**

Permitted and present: load latest, start/refresh, continue progress, cancel when allowed, retry
when allowed, search, filter, sort, cursor-page, expand/collapse, open a permitted frontend URL,
and close.

Absent by design, and to stay absent: media selection, upload, descriptor hydration, image or
gallery assignment, field save, row save, Save Selected, arbitrary meta, raw owner or field
targets, exact filtered totals, rollback, and any mutation whatsoever.

Unsupported nested, conditional, option, and user-scoped field paths are excluded from rows
entirely. They appear only inside the aggregate skipped-observations notice, as counts.

If R2 concepts are requested later, isolate them in a clearly labelled non-production state page.
Initial R2 still has no Save Row and no cross-entity bulk save.
