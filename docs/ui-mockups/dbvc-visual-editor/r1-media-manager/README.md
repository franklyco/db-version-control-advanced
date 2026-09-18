# R1 Media Manager - static mockup

Non-production UI mockup for the DBVC Visual Editor Media Manager, release **R1 (read-only
scan and report)**. Nothing here is wired to WordPress, REST, or any store. Codex translates
the accepted direction through separately reviewed production slices.

## Status

| Sub-phase | Scope | State |
|---|---|---|
| D1 | Evidence and component plan | complete (delivered in chat) |
| D2 | Default desktop mockup | complete |
| D3 | States, responsive, screenshots | complete |
| D4 | Handback package | complete |
| D4A-1 | Contract, fixture, static state, schematic reconciliation | complete |
| D4A-2 | Short-height/mobile layout and interaction corrections | complete |
| D4A-3 | Validation, screenshots, documentation, final handback | **complete - this drop** |

Codex reviewed D1-D4 and **accepted the mockup as visual direction with required adaptations**
(decision D-030). D4A-1 corrected the contract, fixture, static states, and schematic; D4A-2
corrected the short-height/mobile scroll model, touch targets, and announcement copy; D4A-3
refreshed all screenshots against true viewport emulation, ran the full validation sweep including
an axe pass, and finalised the documentation.

**R1-D4A is complete and Codex has signed off the corrected package as qualified static visual
direction.** Production translation has begun separately with the feature-gated toolbar/shell
slice; this directory remains non-production. Codex corrected the inherited subtle-text token at
its production source, synchronized the mirror here, reran axe on both pages, and refreshed all
seven screenshots. See `DESIGN-DECISIONS.md` sections 6.8-6.9 for the qualified evidence.

## Files

| File | Purpose |
|---|---|
| `index.html` | Default view, Home Page expanded; four fixture-backed expansions, two honestly pending |
| `states.html` | State gallery: 13 scan, result, expansion, request-error, paging, focus, and motion cases |
| `styles.css` | Scoped stylesheet, mirrored base tokens + new component tokens |
| `mockup.js` | Optional local-only interaction script (accordion, filters, sort, close, live region) |
| `screenshots/` | Seven refreshed renders: desktop, short desktop, tablet, handset top/bottom, 320px, full gallery |
| `COMPONENT-NOTES.md` | Component inventory, data binding, fixture reconciliation, open questions |
| `WIRING-SCHEMATIC.md` | Selector to route/symbol map, state diagrams, "not wired in R1" |
| `DESIGN-DECISIONS.md` | Decision record, candidate list, validation record, exact inventory, limitations |
| `CODEX-STATUS-UPDATE-HANDOFF.md` | Exact before/after text for the roadmap, phases, and tracker updates, plus a paste-ready Codex prompt |
| `D4-HANDOFF.md` | Session aid from the D3-to-D4 handover; not part of the output contract |

**Read `DESIGN-DECISIONS.md` first if you are Codex.** Section 3 records how each open question
was adjudicated and what changed in the fixture; sections 4-6 carry the exact inventory, the
validation record, and the limitations.

`D4-HANDOFF.md` was kept rather than deleted: it records the D1-D3 session history, and removing
it would discard context no other file holds. It is not a specification.

## How to view

Open `index.html` directly in a browser. No server, no build step, no dependency install.
`states.html` is linked from the drawer footer.

Breakpoints: desktop table above 1024px, tightened table from 641-1024px, card list at 640px and
below. Resize the window to move between them.

Scrolling changes with the viewport. On tall, wide viewports the result table is the scroll region
and the column header pins. At 640px or narrower, **or** 760px or shorter, the drawer itself
becomes one scroll body and only the header pins - a short desktop window gets the same treatment
as a handset, because it clips the same way.

`mockup.js` is optional. Delete it and the page still renders correctly as a static default
view - only the local toggles stop working.

## Screenshots

Refreshed at D4A-3 and regenerated after the authoritative subtle-text token correction. All were captured with the DevTools Protocol using
`Emulation.setDeviceMetricsOverride`, so each renders at its **true layout viewport** - the
requested size and the measured `innerWidth x innerHeight` agree in every row below.

| File | Requested | Layout viewport | Capture (px @ DSF) | Shows |
|---|---|---|---|---|
| `01-desktop-1440x900.png` | 1440x900 | 1440x900 | 2880x1800 @2 | Default desktop: table layout, sticky column header, results region scrolls |
| `02-desktop-short-1440x600.png` | 1440x600 | 1440x600 | 2880x1200 @2 | Short desktop window - the height arm is active and the drawer is the single scroll body |
| `03-tablet-900x1000.png` | 900x1000 | 900x1000 | 1800x2000 @2 | Tablet: tightened table retained |
| `04-mobile-390x844-top.png` | 390x844 | 390x844 | 780x1688 @2 | Handset at top: card layout, sticky header, 44px targets |
| `05-mobile-390x844-bottom.png` | 390x844 | 390x844 | 780x1688 @2 | Handset at **maximum scroll**: last row and footer both reachable |
| `06-mobile-320x568.png` | 320x568 | 320x568 | 640x1136 @2 | Narrowest supported viewport, at a true 320 |
| `07-states-gallery-1280.png` | 1280x1000 | 1280x1000 | 1280x4016 @1 | Full state gallery, all 13 cases |

No horizontal overflow was measured at any of the seven captures.

Earlier `--window-size` captures were retired. That path is clamped to a 500px minimum and then
*crops* the PNG to the width you asked for, so its output width is not the layout viewport - a
"375px" capture was really a 500px render. The CDP path above has no such clamp, which is why the
inventory can state requested and measured viewports separately and have them match.

## Data source

The completed default view - the list projection and all four expansion projections - is
fixture-backed by:

```
docs/dropins/dbvc-visual-editor-brand-controls-guide/ui-ux/fixtures/media-manager-r1c-view-model.json
```

**Not everything on the page has that provenance.** The explicitly labelled state-gallery cases
have no fixture counterpart, and a handful of display strings are synthetic presentation copy
rather than server output. The four tiers below say exactly which is which; do not treat the whole
mockup as fixture-derived.

The fixture was **corrected at D4A-1** under Codex authorization so its declared sort, pagination,
scan-time metadata, and expansion `fields[]` agree with `MediaScanReadModel`.

Not every string on the page has the same standing. Read this before citing the mockup as
evidence of anything:

**Fixture-backed — the completed default view.** The scan state, progress, timestamps, summary
counts, skipped-observation counts, all six result rows, their entity labels, types, missing
counts, family counts, scanned/updated times, front-end availability, the query defaults, and
`pagination` come from the fixture verbatim. So do all four expansion payloads —
`current`, `changed`, `resolved` and `unavailable` — including their statuses, counts,
`newMissingFindingCount`, and field records. Two of the six groups have no expansion payload in
the fixture; those rows stay honestly pending and no field rows are invented for them.

**Contract-exact — the shapes, not the prose.** The `projectFinding()` key shape, the
`status` → `descriptorStatus` enum pairs (`missing`→`not_hydrated`, `changed`→`blocked_stale`,
`resolved_or_changed`→`unavailable`, `unavailable`→`unavailable`), the label-then-`findingRef`
field sort, the retryable provider-error object, and the four operator-facing `message` strings
are copied verbatim from `MediaScanReadModel`. These are the parts production must match.

**Synthetic presentation values — plausible, not literal.** Some field labels and their context
labels ("Team photo" / "About page content", "Archive image" / "Project type media", "Post
gallery" / "Article media"), and the rendered descriptor phrases ("Descriptor: not hydrated",
"Descriptor: blocked (stale evidence)", "Descriptor: unavailable"), were authored for the fixture
and the mockup. They are **display copy, not server output and not new backend vocabulary** — the
descriptor phrases in particular are a UI rendering of the `descriptorStatus` enum, not the enum.
Production renders whatever labels the server actually returns.

**Illustrative — no fixture counterpart.** In `states.html`, cases 1–4 (no current scan, running,
error, cancelled) have no fixture counterpart; their progress numbers, timestamps and error text
are layout placeholders. Case 10 (expansion request 404) and case 11 (partial page with Load more)
illustrate contract-valid responses that are deliberately *not* the default fixture response. Every
one is labelled as illustrative on the page itself. None may be treated as contract values.

## Product boundary held by this mockup

Present: load latest, start/refresh, continue, cancel, retry, search, filter, sort, cursor
paging, expand/collapse, open a permitted frontend URL, close.

Deliberately absent: media selection, upload, descriptor hydration, image/gallery assignment,
field or row save, Save Selected, arbitrary meta, raw owner/field targets, exact filtered
totals, rollback, and any mutation. Unsupported nested/conditional/option/user field paths
appear only inside the aggregate skipped-observations notice, never as rows.

## Constraints observed

No global reset, no unscoped element selector, no framework, no CDN, no font or icon kit, no
build step, no minification, no inline handler, no network request, no persistence, no
production state store. Icons are inline SVG or CSS shapes.

## Not modified

Production PHP/JS/CSS, tests, generated agent docs, REST routes, descriptors, Media Library
behaviour, and mutation systems are untouched.

**One authorized exception at D4A-1.** Codex lifted the package-write prohibition for the existing
R1-C fixture and its existing records. The fixture was corrected, and its `bytes`/`sha256` entry in
`PACKAGE-MANIFEST.json` plus its single line in `PACKAGE-CONTENTS.sha256` were updated in place. No
entry was added or removed and the package version stays at 2.4.2. Mockup files under
`docs/ui-mockups/` are still deliberately absent from the package manifest - they sit outside the
package.

**One authorized exception at D4A-2.** `file_count_without_manifest_or_checksum` in
`PACKAGE-MANIFEST.json` was corrected from 45 to 46 on Codex's instruction, to match the 46 entries
already in `files[]`. Nothing else in the manifest, the checksum file, the fixture, or the package
version changed.

`docs/ROADMAP.md`, the phases guide, and the tracker were updated by **Codex**, not by this drop.
