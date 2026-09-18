# R5.later-perf — Measurement Recipe

**Purpose.** Step-by-step procedure for capturing the audit's baseline
measurements in a real foregrounded Chrome session, using the gated
`dbvc.ve.*` User Timing spans landed in R5.later-perf Task 2.

**Why this is a recipe (not automation).** Per E-123, Claude-in-Chrome
runs backgrounded and throttles `IntersectionObserver` callbacks; its
viewport is retina-mapped (1440×900 requests map to 1728×958 CSS pixels).
Either would systematically wrong the audit's numbers. **The maintainer
executes this recipe in real, foregrounded Chrome. Numbers captured
inside an automation session are not admissible evidence for this audit.**

**Output shape.** For each scenario, produce one JSON file at
`docs/dropins/dbvc-visual-editor-brand-controls-guide/qa/R5-LATER-PERF-MEASUREMENTS/{scenario-id}.json`
containing an array of 5 counted runs (the warmup run is discarded).
Analysis is done in Task 5 of the phase; Task 6 writes the report.

**JSON-only.** No flame-chart screenshots this pass (maintainer decision
2026-09-06). If a scenario's numbers are surprising and a flame chart
would help explain them, capture one ad-hoc into the same directory as
`{scenario-id}-flame.png`.

---

## Environment gate (do this once at the start of every session)

Before recording, confirm the environment matches what the audit
report will document:

1. **Real foregrounded Chrome window**, tab is the frontmost / active
   tab throughout each recording (background-tab throttling changes
   IntersectionObserver + `setTimeout` timings).
2. **DevTools open** in its own window (not docked-right, which shrinks
   the CSS viewport). Alternatively, dock-bottom is fine — but pick one
   docking mode and use it consistently across all scenarios.
3. **No other tabs playing media** in the browser process.
4. **LocalWP site cold** — restart the LocalWP site (Stop → Start) at
   the beginning of a scenario batch so the `wp_options` autoload cache
   + PHP-FPM workers + mysql query cache are all in a repeatable state.
   Warmup run then discards the JIT/opcode-cache slope from the counted
   runs.
5. **Test data snapshot noted** — capture `wp db size` + a `wp option
   get dbvc_visual_editor_control_center_enabled` + the site's
   `viewModelVersion` at the top of every scenario's JSON so the audit
   can prove a shift in test data isn't confounding a shift in numbers.

**Enable the profiler**: append `?dbvc_ve_perf=1` to the URL of every
page you record. The overlay-app's built-in spans emit unconditionally
(they've been there since D-064); the drawer + api-client spans landed
in R5.later-perf Task 2 ONLY emit when this flag is present. If a JSON
capture is missing `dbvc.ve.drawer.*` or `dbvc.ve.api.*` entries, the
flag wasn't on — retry the run.

---

## Universal capture snippet (paste into DevTools Console)

Use this snippet at the end of each run to extract the User Timing
entries + write them to a downloadable JSON blob. It filters to just
the `dbvc.ve.*` spans (leaving any third-party spans out), sorts by
start time, and includes the `duration` (`.duration` field — this is
the wall-clock ms between the start and end marks).

```js
(function () {
  const entries = performance
    .getEntriesByType('measure')
    .filter((e) => e.name.startsWith('dbvc.ve.'))
    .map((e) => ({
      name: e.name,
      startTime: Math.round(e.startTime * 100) / 100,
      duration: Math.round(e.duration * 1000) / 1000,
    }))
    .sort((a, b) => a.startTime - b.startTime);
  const meta = {
    capturedAt: new Date().toISOString(),
    href: location.href,
    viewport: { w: window.innerWidth, h: window.innerHeight,
      dpr: window.devicePixelRatio },
    ua: navigator.userAgent,
    perfFlag: new URLSearchParams(location.search).get('dbvc_ve_perf') === '1',
    entries,
  };
  const blob = new Blob([JSON.stringify(meta, null, 2)],
    { type: 'application/json' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'perf-' + Date.now() + '.json';
  document.body.appendChild(a);
  a.click();
  a.remove();
  console.table(entries.map((e) => ({
    span: e.name.replace(/^dbvc\.ve\./, ''),
    startMs: e.startTime,
    durMs: e.duration,
  })));
  return meta;
})();
```

Downloads land in the browser's default Downloads directory as
`perf-<epoch>.json`. Rename + move to
`docs/dropins/dbvc-visual-editor-brand-controls-guide/qa/R5-LATER-PERF-MEASUREMENTS/{scenario-id}-run{N}.json`
after each run.

**Between runs**, clear the User Timing buffer so the next capture
doesn't include the previous run's entries:

```js
performance.clearMeasures();
performance.clearMarks();
```

---

## Discipline

- **Warmup discarded.** For every scenario, run **6 total** and discard
  the first (LocalWP's opcache + WP autoload cache warms during it).
  Keep runs 2–6; report the median + p10 + p90.
- **Between counted runs** — hard-refresh (Cmd+Shift+R) unless the
  scenario specifies "warm start" (S3 is the only warm-start scenario).
  Wait for the page's `readyState === 'complete'` before recording the
  scenario's action.
- **DevTools Performance panel** — leave it open on the User Timing
  track for visual sanity-check per run; the JSON capture is
  authoritative but the flame view catches obvious anomalies (a rogue
  extension firing during your run, a stale service worker, etc.).
- **Note anomalies in the JSON's `notes` field** — if run 3 saw an
  Adobe update kick in mid-scenario, mark it. The analysis pass in
  Task 5 discards flagged runs and re-runs to backfill.

---

## Scenarios

Every scenario writes to
`docs/dropins/dbvc-visual-editor-brand-controls-guide/qa/R5-LATER-PERF-MEASUREMENTS/{scenario-id}/`
as `run1.json` … `run5.json` (warmup omitted; see Discipline above).

### S1 — Cold overlay boot at 1440×900

**Question answered.** How expensive is the initial `?dbvc_ve_editmode=1`
overlay-app boot at the primary supported viewport, cold?

**Setup.**
1. Restart the LocalWP site.
2. Resize the Chrome window so the viewport (CSS pixels, `window.innerWidth
   × window.innerHeight`) is **1440×900**. Confirm in Console.
3. Navigate away from the LocalWP site (e.g. `about:blank`) between
   runs to keep the load path cold.

**Per run.**
1. Hard-refresh a page that surfaces the overlay
   (`https://dbvc-codexchanges.local/?dbvc_ve_editmode=1&dbvc_ve_perf=1`).
2. Wait for `document.readyState === 'complete'` **and** the overlay-app's
   `dbvc.ve.overlay_boot` span to appear in Performance (typically
   within 1–2s of load).
3. Wait an additional **2s** for delayed refresh timers to settle
   (the overlay-app schedules 250ms/1000ms/2500ms
   `refreshQueryCollectionBadges` timers after boot).
4. Run the universal capture snippet.

**Expect (existing spans).** `overlay_boot`, `session.public_map_request`,
`session.public_map_sync`, `markers.scan`, `markers.mount`,
`badges.query_collection_mount`, `prefetch.setup`, `api.get_session`.

### S2 — Cold overlay boot at 1280×720

**Question answered.** Does the smaller supported viewport materially
change the boot path (fewer markers in initial viewport → smaller
prefetch batch)?

**Setup + per run.** Identical to S1 except viewport = **1280×720**
CSS pixels. Same page. Same wait. Same capture.

**Compare against.** S1's medians. Small numeric differences are
expected (fewer markers = less mount work); large gaps (>25%) may
indicate viewport-conditional code that isn't shared across the two
supported sizes.

### S3 — Drawer open → list fetch → first render (cold)

**Question answered.** How long does the drawer's first-open take from
click to first painted row, cold?

**Setup.**
1. From S1's steady state (overlay booted, page idle), clear User
   Timing (`clearMeasures()` / `clearMarks()` in Console).
2. Ensure the drawer has not been opened yet in this session.

**Per run.**
1. Click the "Global Brand Controls" toolbar icon (or dispatch
   `document.dispatchEvent(new CustomEvent('dbvc:visual-editor:control-center:toggle'))`
   from Console — identical code path).
2. Wait for the drawer's row list to visually populate (rows are in
   the DOM, filter chips are rendered). Typically ~200–800ms depending
   on record count.
3. Wait an additional 500ms for any post-render settle.
4. Capture snippet.
5. Close the drawer (Escape) and hard-refresh (Cmd+Shift+R) between
   runs so `state.hasLoaded` resets.

**Expect (new spans from Task 2).** `drawer.open`, `drawer.list_fetch`,
`drawer.render_list`.

### S4 — Drawer scroll → IntersectionObserver hydration cascade

**Question answered.** How does R4-C-1b's value-summary hydration
scale across a 400-row list? Is the IO-callback overhead comparable
to the batch fetch, or dwarfed by it?

**Setup.**
1. From S3's steady state (drawer open, list rendered).
2. Confirm the site has ≥100 rows visible in the current filter (if
   not, clear filters to All / All-Available first).

**Per run.**
1. Clear User Timing.
2. Scroll the drawer's row list from top to bottom **at a natural
   pace** — roughly 3 seconds to cross the full list. Use the mouse
   wheel or arrow keys, not a JS scroll-to-bottom (which fires all IO
   callbacks in one microtask and skews the picture).
3. Wait 2s after reaching the bottom for the last batch flush to
   complete.
4. Capture snippet.
5. Between runs, close the drawer + hard-refresh.

**Expect.** Many instances of `drawer.value_summary.io_trigger`
(one per IO callback firing) + `drawer.value_summary.batch_flush`
(one per HTTP POST — batches cap at 20 per D-064, so a 400-row scroll
should produce ~20 flushes at most). Note in the JSON's `notes` field
if any batches appear to be < 20 rows (that would suggest the
50ms flush window is closing before rows have queued — a
sub-optimality worth calling out).

### S5 — Single-color panel open (marker click)

**Question answered.** How long from a marker click to a rendered
editor panel, cold cache?

**Setup.**
1. From S1's steady state (overlay booted, drawer NOT opened yet).
2. Confirm at least one `.dbvc-ve-target` marker is visible on the
   page (any editable ACF field with an R5.2-shipped family).

**Per run.**
1. Clear User Timing.
2. Click a marker (any `.dbvc-ve-target`) — the toolbar-descriptor
   path takes over.
3. Wait for the editor panel to render (input control is visible in
   the panel body).
4. Wait 500ms.
5. Capture snippet.
6. Close the panel + hard-refresh between runs.

**Expect.** `panel.open.marker`, `descriptor_request`, `panel.render`,
`api.get_descriptor`. (Some paths use `api.get_descriptors` — the
batch variant — depending on prefetch state.)

### S6 — Palette-parent Open → panel palette render

**Question answered.** How expensive is the R5.later-y palette
overview panel to open? This is the specific panel R5.later-c will
extend (or lean on R5.later-cache for).

**Setup.**
1. From S3's steady state (drawer open, list rendered).
2. Scroll to a palette-parent row (Vertical has the Global Palette
   parent with 19 leaves).

**Per run.**
1. Clear User Timing.
2. Click the palette parent's Open button.
3. Wait for the swatch grid to fully render (all N cells + hex
   labels present).
4. Wait 500ms.
5. Capture snippet.
6. Close the panel + hard-refresh between runs.

**Expect.** `drawer.open_row`, `panel.open.toolbar`, `panel.render`.
The palette parent's `absorb-descriptor` path goes through the same
`openToolbarDescriptorPanel` as any other shared-global panel.

### S7 — Palette swatch save, lazy-open path (first edit)

**Question answered.** Does the first save on a fresh swatch really
take 2 round-trips? (Lazy-open + save per R5.later-y-2.)

**Setup.**
1. From S6's steady state (palette panel open, swatch grid rendered).
2. Pick a swatch that has NOT been edited this session (any swatch,
   as long as no other run just hit it — start with the first swatch,
   move to the next between runs).

**Per run.**
1. Clear User Timing.
2. Click the swatch → native color picker opens.
3. Pick a color (any color; the exact value doesn't matter — pick
   something close to the current value so you don't visually alter
   the site during measurement).
4. Wait for the cell's status indicator to reach `"Saved"` (~500ms
   after the 250ms debounce fires).
5. Wait 500ms.
6. Capture snippet.
7. Hard-refresh + reopen the palette panel + pick a different swatch
   for the next run so every counted run is a "first edit."

**Expect.** `drawer.open_row` NOT present (we're already in the
panel). `api.save` present. **A direct `fetch` call to
`/control-center/open` is present but NOT wrapped in a `dbvc.ve.*`
span** (the palette overview controller uses `window.fetch` directly,
not the api-client's `save`). This is expected — the lazy-open
round-trip is visible in the Network panel + inferable from the gap
between the `dbvc.ve.save.request` start and the debounce fire. Note
the gap in the JSON's `notes` field.

### S8 — Palette swatch save, pre-minted-token path (R5.later-y-3)

**Question answered.** Did R5.later-y-3's pre-minted token actually
eliminate the lazy-open round-trip? (Should see only 1 network call
per save, not 2.)

**Setup + per run.** Identical to S7 except: this scenario reuses
a swatch that has already been edited once this session — the
second and subsequent edits use the cached token, which per
R5.later-y-3 is pre-minted at panel-open time. So:

1. From S6's steady state, pick swatch #1 and edit it once (this
   is the pre-warm — its timings are S7's data, not S8's).
2. Clear User Timing.
3. Edit swatch #1 AGAIN (different color).
4. Wait for `"Saved"` + 500ms.
5. Capture snippet.

**Expect.** `api.save` present. **No `fetch` to `/control-center/open`
in the Network panel** — the token is cached. If a POST to
`/control-center/open` DOES appear, R5.later-y-3's pre-mint has
regressed and the audit must call this out as a top-3 finding.

---

## After the batch

When all 8 scenarios have `run1.json` … `run5.json` in place, ping
back. Task 5 (analysis) ingests the JSON files, computes medians +
p10/p90 per span, and Task 6 writes the audit report from those
numbers.

**Rough time budget for the maintainer.** Environment setup + S1 =
~15 min. Each additional scenario ≈ 8–12 min (6 runs × ~1 min each,
plus hard-refresh + wait). Total ≈ 90 minutes for a clean sweep.

---

## Amendments from the 2026-09-17 sweep (E-162)

Learned running S1–S6 for real (`R5-LATER-PERF-AUDIT-REPORT.md` §2 has the exact per-scenario procedure used):

- **Assert `document.hidden === false` inside every capture** and discard the run if it is true — a background tab, a macOS fullscreen Chrome on another Space, or a fully occluded window all throttle timers ≈ 25× (E-123 re-confirmed: `badges.query_collection_mount` 1 201 ms hidden vs ≈ 50 ms foreground).
- `about:blank` cannot be used as the between-runs page from the browser tooling; a static asset URL on the site (e.g. `assets/css/workspace.css`) works and keeps the load path cold.
- **S4:** call `performance.setResourceTimingBufferSize(5000)` before the scroll (the default 250 overflows), poll until every value-summary POST has completed, and record from Resource Timing per request: `responseStart − requestStart` (server wait), `requestStart − startTime` (client queueing) and the overlapping-request maximum (real concurrency). Between S4 runs wait until `curl -w '%{time_starttransfer}' …/wp-json/` is back under ≈ 1.5 s — dispatched batches keep running server-side after navigation.
- **S5:** clicking the marker itself does nothing; the panel opens from the **Edit badge** (`.dbvc-ve-badge`) that appears on hover. Hover un-pauses the viewport descriptor prefetch, so the hover → click delay decides whether the panel waits on an in-flight request (300 ms used).
- **S6:** the palette parent row lives in the collapsed-by-default `Site Settings Advanced` group; expand it (or all groups) in setup and let the row's own value-summary batch finish before clearing timing.
- Park the mouse on the admin bar between runs — a cursor resting on a page marker keeps the prefetch active and contaminates S3/S4 (`s4/discarded-hover-prefetch.json`).

