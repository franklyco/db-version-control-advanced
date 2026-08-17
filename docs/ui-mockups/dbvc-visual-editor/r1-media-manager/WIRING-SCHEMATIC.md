# Wiring schematic - R1 Media Manager mockup (final, D4A-3)

Maps each major selector to purpose, safe data, local behavior, future route and server symbol,
states, accessibility, and scope. Scope values: `R1` (in this release), `R2 deferred`,
`mockup-only`.

**The static mockup calls none of these routes.** All routes require the Media Manager feature
flag, Visual Editor capability, active signed mode, WordPress REST authentication, and current
user/site snapshot ownership. Explicit scan actions carry the current generation and expected
revision.

Route prefix: `/dbvc/v1/visual-editor/media-manager`.

## 1. Selector map

### Shell

| Selector | Purpose | Safe data | Local behavior | Future route -> symbol | States | A11y / focus | Scope |
|---|---|---|---|---|---|---|---|
| `.dbvc-ve-media-manager` | Drawer root | `scan.state` via `data-state` | none | - | `idle` `running` `complete` `cancelled` `error` | `<section aria-labelledby>`, non-modal | R1 |
| `__header` | Identity + summary | - | none | - | - | `<h1 id="mm-title">` names the region | R1 |
| `__summary-metric` | Scan-wide counts | `summary.entitiesWithFindings`, `.totalFindings`, `.candidateEntitiesProcessed` | none | `GET /scans/latest` -> `handleLatest()` -> `MediaScanReadModel::getLatestList()` | - | `<dl>` pairs label to value | R1 |
| `__close` | Dismiss drawer | - | `data-mockup-action="close"` | client-side only | - | visually hidden accessible name; returns focus to toolbar | R1 |
| `__footer-note` | Read-only disclaimer | - | none | - | - | static text | R1 |
| `.dbvc-ve-media-manager-mockup__stage` | Faux live page | - | none | - | - | `aria-hidden`, no focusable nodes | mockup-only |
| `.dbvc-ve-media-manager-mockup__toolbar` | Toolbar continuity | - | none | - | - | `aria-hidden`; production uses real `.dbvc-ve-toolbar` | mockup-only |

### Scan control

| Selector | Purpose | Safe data | Local behavior | Future route -> symbol | States | A11y / focus | Scope |
|---|---|---|---|---|---|---|---|
| `__scan-state` | Scan state | `scan.state`, `requestStatus` | none | `GET /scans/latest` -> `handleLatest()` | `is-complete` `is-running` `is-error` `is-cancelled` | dot + text + color; never color alone | R1 |
| `__progress` | Chunk progress | `progress.processed`, `.totalEstimate` | none | `GET /scans/{scanRef}` -> `handleList()` -> `getList()` | determinate; indeterminate when estimate absent | native `<progress>`, visually hidden label | R1 |
| `__scan-times` | Snapshot lifetime | `startedAt`, `completedAt`, `expiresAt` | none | - | - | `<time datetime>` machine readable | R1 |
| `__button` Refresh | Start a new scan | - | `data-mockup-action="refresh"` | `POST /scans` -> `handleStart()` -> `MediaScanCoordinator::start()` | enabled unless a scan is running | focus ring; announce via live region | R1 |
| `__button` Continue | Run next chunk | `progress.chunks`, `.attempts` | inert | `POST /scans/{scanRef}/next` -> `handleNext()` -> `runNextChunk()` | rendered only while `running` | as above | R1 |
| `__button` Cancel | Stop a scan | `canCancel` | inert | `POST /scans/{scanRef}/cancel` -> `handleCancel()` -> `cancel()` | rendered only when `canCancel` | as above | R1 |
| `__button--danger` Retry | Retry after error | `canRetry` | inert | `POST /scans/{scanRef}/retry` -> `handleRetry()` -> `retry()` | rendered only when `canRetry` | as above | R1 |

Absent controls are **omitted from the DOM**, never rendered disabled.

### Notices

| Selector | Purpose | Safe data | Future route -> symbol | Scope |
|---|---|---|---|---|
| `__notice.is-info` | Aggregate skipped observations | `summary.unsupportedFieldObservations`, `.invalidNonemptyValues` | `getLatestList()` / `getList()` | R1 |
| `__notice.is-warning` | Snapshot expiry | `scan.expiresAt` | as above | R1 |
| `__notice.is-error` | Scan failure text | `scan.error` (safe message only) | as above | R1 |

Skipped observations are counts only. Unsupported nested, conditional, option, and user-scoped
paths never become rows.

### Query controls

| Selector | Purpose | Safe data | Local behavior | Future route -> symbol | A11y | Scope |
|---|---|---|---|---|---|---|
| `__search` | Text filter | `query.search` (<=100 chars) | `data-mockup-input="search"` | `GET /scans/{scanRef}` -> `handleList()` -> `getList()` | `<label for>`; `maxlength=100` | R1 |
| `__chip-set` entity | Entity family | `query.entityFamily` = `all\|post\|term` | `data-mockup-input="entity-family"` | as above | `<fieldset><legend>` + radios | R1 |
| `__chip-set` field | Field family | `query.fieldFamily` = `all\|featured_image\|acf_image\|acf_gallery` | `data-mockup-input="field-family"` | as above | as above | R1 |
| `__select` sort | Sort order | `query.sort` | `data-mockup-input="sort"` | as above | `<label for>`; mirrored by `aria-sort` on the active `th` | R1 |
| `__scope-badge` | Fixed scope indicator | - | none | server-fixed | static `<p>`, not a control | R1 |
| `__legend` | Family counts + status key | `summary.*Findings` | none | as above | text labels, not color-only | R1 |

Query controls re-request the server page. They never re-sort or re-filter a delivered page
client-side, and never derive a filtered total.

### Results

| Selector | Purpose | Safe data | Local behavior | Future route -> symbol | States | A11y / focus | Scope |
|---|---|---|---|---|---|---|---|
| `__results` | Result region | - | none | - | - | `tabindex="-1"` for programmatic focus. **Scrolls independently only on tall/wide viewports**; below 640px wide or 760px tall the drawer is the single scroll body and this element is `overflow: visible` | R1 |
| `__thead` | Column headers | - | none | - | `aria-sort` on active column, kept in sync with the server order | `position: sticky` on tall/wide viewports; visually hidden in card mode; static when short-and-wide so it cannot collide with the sticky header | R1 |
| `__row` | Entity group | `groupRef` (opaque) | `data-mockup-group` | `getList()` | `is-expanded` | `<th scope="row">` on entity cell | R1 |
| `__entity-label` | Entity name | `entity.label` | none | as above | 2-line clamp | `title` carries the full string | R1 |
| `__type-chip` / `__family-note` | Type + family | `entity.typeLabel`, `entity.family` | none | as above | `is-term` | display only, never a filter value | R1 |
| `__missing-chip` | Missing count | `missingCount` | none | as above | singular / plural | text + color | R1 |
| `__family-chip` | Per-family counts | `findingCounts.featuredImage/acfImage/acfGallery` | none | as above | zero counts omitted | leading glyph distinguishes without color | R1 |
| `__cell--time` scanned | Scan freshness | `scannedAt` | none | as above | - | `<time datetime>` | R1 |
| `__cell--time` updated | Content freshness | `modifiedGmt` | none | as above | empty -> "Not recorded" | `<time>` or muted text | R1 |
| `__open-link` | Open front end | `entity.frontendUrl`, `availableActions.openFrontend` | native link | none (public URL) | omitted when `openFrontend` is false | `rel="noopener noreferrer"`; hidden text announces new tab | R1 |
| `__row-toggle` | Expand / collapse | `availableActions.expand` | `data-mockup-action="toggle-row"` | `GET /scans/{scanRef}/groups/{groupRef}` -> `handleGroup()` -> `MediaScanReadModel::expandGroup()` | `aria-expanded` true/false | `aria-controls` -> expansion row id | R1 |
| `__expansion` | Revalidated detail | expansion `status` | none | as above | `current` `changed` `resolved_or_changed` `unavailable` `is-pending` | receives focus after expand | R1 |
| `__expansion-counts` | Status tally | `counts.*` | none | as above | - | chips carry label + number | R1 |
| `__expansion-note` | New-missing note | `newMissingFindingCount` | none | as above | - | text | R1 |
| `__field` | One finding | `findingRef` (opaque), `label`, `family`, `contextLabel`, `status`, `descriptorStatus`, `message` | none | as above | `data-status` | list semantics; status as text + chip | R1 |
| `__empty` | No matches | - | none | `getList()` | `hidden` in default view | announced via live region | R1 |
| `__load-more` | Next cursor page | `pagination.hasMore`, `.nextCursor` | `data-mockup-action="load-more"` | `getList()` with cursor | rendered only when `hasMore`; **omitted from `index.html`** because the corrected fixture reports `hasMore: false` with an empty cursor | appended rows announced politely | R1 |
| live region | Status announcements | derived | `data-mockup-live="announcer"` | - | - | `role="status"`, `aria-live="polite"`, `aria-atomic` | mockup-only pattern, carry into production |

## 2. Scan state diagram

```
                  POST /scans (start)
      idle ───────────────────────────────▶ running
                                              │
                    POST /scans/{ref}/next    │  (repeats per chunk)
                        ┌─────────────────────┤
                        └────────────────────▶│
                                              │
        canCancel ──── POST .../cancel ───────┼──────────▶ cancelled
                                              │
                                              ├──────────▶ complete
                                              │
                                              └──────────▶ error
                                                             │
                             canRetry ── POST .../retry ─────┘  ─▶ running

  Any state ── GET /scans/latest ─▶ rehydrate on open/resume
  complete ── expiresAt passed ──▶ snapshot stale; refresh or expand to revalidate
```

Control visibility: Continue only while `running`; Cancel only while `canCancel`; Retry only
while `canRetry`; Refresh whenever no scan is running.

## 2.1 Backend state to client presentation adapter (D4A-1)

The diagram above uses friendly names. **The server does not emit them.** Backend evidence and
client presentation are separate vocabularies joined by this explicit adapter, and production must
implement it as an adapter rather than assuming the two match.

| Backend evidence | Client presentation | Notes |
|---|---|---|
| `scanning` | running / in progress | Continue is offered per the chunk contract |
| `failed` | error | Actions come **strictly** from `canRetry` / `canCancel` |
| `canceled` | canceled | Partial results stay readable |
| `complete` | complete | |
| `invalidated` | configuration changed; a fresh scan is required | Not an error, and not resumable |
| `GET /scans/latest` or list → `404 media_scan_expired_or_invalid` | **no current scan** / expired-unavailable | Must **not** claim the scan was never run - the contract cannot distinguish never-created from expired |
| `GET /scans/{ref}/groups/{ref}` → `404 media_scan_group_unavailable` | expansion request unavailable; refresh list or scan | **Request state.** No `row`, no `counts`, no `fields[]` - render no counts |
| row `status: unavailable` **with** `fields[]` | provider revalidation failed safely | **Row data.** Group resolved, fields returned, rescan provider failed; render the fields and the safe retryable error |

Two rules this table exists to enforce:

1. **Request states are not row statuses.** Generation/revision conflict, stale, terminal, and
   superseded responses are request outcomes. They never map onto `current`, `changed`,
   `resolved_or_changed`, or provider-`unavailable`. Suppress stale responses and keep the newest
   accepted state.
2. **Never infer an action from a label.** A friendly "error" heading grants no Retry. Only
   `canRetry` and `canCancel` do.

Note that the last two rows are the pair most easily confused. `media_scan_group_unavailable` is
a 404 with no payload - the group is gone, unpublished, deleted, out of scope, or expired from the
snapshot. Row `status: unavailable` is a **200** carrying real fields whose revalidation could not
complete. `states.html` cases 9 and 10 show them side by side.

## 3. Row / expansion state diagram

```
   collapsed ──▶ (toggle, aria-expanded=true) ──▶ pending
                                                    │
                       GET /scans/{ref}/groups/{groupRef}
                                                    │
                        ┌───────────────────────────┴───────────────────┐
                        │ 200 row payload                               │ 404
                        ▼                                               ▼
   ┌───────────┬───────────┬─────────────────────┬──────────────┐   request error
   ▼           ▼           ▼                     ▼              │   media_scan_group_unavailable
current     changed   resolved_or_changed   unavailable         │   (no row, no counts, no fields)
   │            │            │              + fields[]          │            │
   │            │            │              + safe error        │            │
   └────────────┴────────────┴──────────────────┴───────────────┘            │
                              │                                              │
                (toggle) ─────┴──────────▶ collapsed ◀─────────────────────── ┘
```

Only one row is expanded at a time. Expanding a second row collapses the first. Each expansion is
a fresh revalidation, so its status may differ from the row's scan-time snapshot.

**The 404 branch is a request error, not a row status.** It carries no `row` object, so no counts
and no field records may be rendered for it - inventing a count there would misreport the entity.
The four statuses on the left all arrive with a `row` payload, and all four include `fields[]`:
`current`, `changed`, and `resolved_or_changed` come from `expandGroup()`, while provider
`unavailable` comes from `unavailableGroupResponse()` and adds a safe retryable `error` object.

`fields[]` is built from the **original** snapshot findings, sorted by label then `findingRef`. A
finding first observed after the snapshot is reported only through `newMissingFindingCount` and
never appears as a row.

## 4. Not wired in R1

Deliberately absent from the DOM and the stylesheet. Every item below appears in the R1-D
concept image (`ui-ux/reference-images/04-media-manager-initial-concept.png`) and was rejected
against the R1 boundary.

| Concept-image element | Why excluded | Earliest release |
|---|---|---|
| Row and select-all checkboxes | Selection exists only to drive save | R2 deferred |
| `Save selected (0)` | Cross-entity bulk save; not in initial R2 either | after R2 |
| `Save Row` (row and expansion) | Field/row mutation | after R2 |
| `Choose Media`, `Upload New` | Media selection and upload | R2 deferred |
| `Manage Gallery`, `Add Images` | Gallery assignment | R2 deferred |
| Per-row `...` overflow menu | Only exposes unavailable actions | R2 deferred |
| Drag handles | Reordering; no backing data | not planned |
| `hero_image (ACF Image)`, `project_gallery (Gallery)` | Raw owner/field targets must not reach the DOM | never |
| `Published Only` switch | R1 scope is server-fixed; a switch implies authority | R2 at earliest |
| `Listings` filter tab | Not an entity family; `Listing` is a `typeLabel` | n/a |
| Sortable `Updated` header | `modifiedGmt` is not an allowlisted sort key | n/a |
| Exact totals `36 Entities / 74 Missing Fields` as filtered figures | Adapted to scan-wide summary counts labelled "in last scan" | n/a |
| Page X of Y | R1 pages by opaque cursor only | never |

Also not simulated: descriptor hydration (`availableActions.hydrateDescriptor` is false
throughout the fixture), rollback, arbitrary meta entry, and any write path.

## 5. Local script behaviour (`mockup.js`, mockup-only)

Optional and deletable. Every entry below is `mockup-only` scope: none of it is a production
contract, and the file issues no request, writes no storage, and mutates no data.

| Trigger | Local behaviour | What production does instead |
|---|---|---|
| `[data-mockup-action="toggle-row"]` | Collapses any other open row, flips `aria-expanded`, unhides the expansion, moves focus into it, announces | `GET /scans/{scanRef}/groups/{groupRef}` -> `handleGroup()` -> `expandGroup()`, then render the returned status |
| `[data-mockup-input="search"]` (input) | Filters rows on `data-entity-label` | Re-request via `handleList()` -> `getList()` |
| `[data-mockup-input="entity-family"]` | Filters on `data-entity-family` | as above |
| `[data-mockup-input="field-family"]` | Filters on `data-field-families` | as above |
| `[data-mockup-input="sort"]` | Reorders row/expansion pairs, moves `aria-sort` to the matching header | as above; **production never re-sorts a server-ordered page** |
| `[data-mockup-action="refresh"]` | Announces only | `POST /scans` -> `handleStart()` -> `start()` |
| `[data-mockup-action="load-more"]` | Disables itself and reports that one fixture page is bundled | `getList()` with `pagination.nextCursor` |
| `[data-mockup-action="close"]` / Escape | Hides the drawer, reveals the mockup-only reopen button | Close the panel; the overlay toolbar keeps the entry point |
| any of the above | Writes a sentence to the polite live region | same pattern; carry the live region into production |

The empty state is shown when the local filter matches nothing. It reports "no entities match
these filters" and never a filtered count, because R1 does not expose exact filtered totals.

## 6. D4 closing pass - verification status of this map

The selector map above was re-checked against the delivered markup at D4 rather than accepted
from D3. What that check can and cannot establish:

**Mechanically verified.** Every `aria-controls`, `aria-labelledby`, `aria-describedby`, and
`label[for]` reference in `index.html` and `states.html` resolves to an element that exists
(6 + 1 + 0 + 10 and 0 + 0 + 0 + 6 references respectively, all resolving); all ids are unique
(17 and 6); the ARIA role census matches the table contract (`table` 1, `rowgroup` 2, `row` 13,
`columnheader` 8, `rowheader` 6, `cell` 48, `status` 1); `<colgroup>` declares 8 columns against
8 `th[scope="col"]` and a single `colspan="8"` expansion row. See `DESIGN-DECISIONS.md` section 5
for the full record.

**Asserted, not verified.** Every "Future route -> symbol" cell in this document is a *design
intention*. The static mockup issues no request, so nothing here proves the route exists, that
its handler is named as written, or that its response shape matches the column named under "Safe
data". Those cells were transcribed from the capsule's wiring map, not from the controller
source. Codex must reconcile each row against
`addons/visual-editor/src/Rest/Controllers/MediaManagerController.php` and
`addons/visual-editor/src/MediaManager/MediaScanReadModel.php` before translation, and should
treat a mismatch as a fault in this document rather than in the server.

The known-unverified item inside that set is the sort allowlist: only `entity_asc` is
fixture-confirmed, while `missing_desc` and `scanned_desc` are placeholders. They appear in the
`__select` row above and as `data-mockup-sortcol` attributes on two `<th>` elements.

## 7. Responsive scroll model and announcements (D4A-2)

**Scroll model.** The drawer has two layouts, selected by viewport rather than by device:

| Condition | Scroll body | Pinned | `__results` |
|---|---|---|---|
| Wider than 640px **and** taller than 760px | `__results` | `<thead>` sticky | `overflow-y: auto` |
| 640px or narrower **or** 760px or shorter | the drawer itself | `__header` sticky only | `overflow: visible` |

Production must implement both. The second is not a phone special case: a short desktop window
clips identically, which is why the trigger is a height query as well as a width query.

**Announcement contract.** The live region must describe what the DOM shows at the moment it is
read, never what a request is expected to return:

| Situation | Announcement |
|---|---|
| Expanding a row whose panel is pending | "Expanded {entity}. Requesting field detail. Still pending." |
| Expanding a row whose panel carries records | "Expanded {entity}. {status sentence} {n} field(s) listed." |
| Collapsing | "Collapsed {entity}." |
| Query returns rows | "Showing {n} entity/entities on this page." |
| Query returns nothing | "No entities match these filters." |

In production the first message is announced when the request is issued and the second only after
`expandGroup()` responds. A final result is never announced while the panel still shows pending.
Counts are pluralised through a single helper, so "1 entity" and "6 entities" are both correct.

## 8. D4A-3 verification status

Re-verified against the current markup at D4A-3, not carried forward:

- Unique ids, and every `aria-controls` / `aria-labelledby` / `aria-describedby` / `label[for]`
  reference resolves.
- Rendered row order is alphabetical and `aria-sort="ascending"` sits on the Entity header, so the
  declared `entity_asc` and the DOM agree.
- `__load-more` is absent from the default response, present only in the illustrative partial-page
  case.
- One expanded row at a time, verified through dispatched keyboard activation.
- Live-region output matches the rendered panel in both the completed and pending cases.
- Tab order: close, refresh, search, entity filters, field filters, sort, then each row toggle and
  front-end link. All stops show a visible focus indicator.

Every "Future route -> symbol" cell remains a *design intention* because the mockup issues no
request. Codex reconciled the map against `MediaManagerController` and `MediaScanReadModel` at
D4A sign-off. Production translation still needs its own request/response tests before any map row
becomes runtime evidence.
