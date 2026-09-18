# R5.later-perf — Frontend performance audit report (2026-09-17)

**Status:** S1–S6 measured (30 counted runs, 5 discarded and annotated); S7/S8 are maintainer-only (they save swatches — see §7). Server-side attribution of every slow endpoint is in §4; recommendations in §5. **F2 + F3 landed as R5.later-perf-c (E-163, `releases/R5.LATER-PERF-C-CONTROL-CENTER-SERVER-COSTS.md`)** — F1 is now the dominant open item.
**Recipe:** `R5-LATER-PERF-MEASUREMENT-RECIPE.md`. **Raw data:** `R5-LATER-PERF-MEASUREMENTS/s1..s6/run1..run5.json` (+ `discarded-*.json`). **Precondition met:** both R5.later-perf switches on (`/wp-json/` TTFB 0.70–0.92 s, was 4.9 s — E-159/E-160/E-161).

## 1. Headline

**The drawer's own client work is negligible; every user-visible wait is a WordPress request, and three of them are expensive for reasons that are entirely inside DBVC + the Vertical provider — not Bricks, not the host:**

| What the editor waits for | Measured (median, 5 runs) | Where the time goes |
|---|---|---|
| Overlay boot (S1/S2) | **1.27–1.30 s** | `api.getSession` 1.19–1.22 s (= 0.7 s WP floor + ≈ 0.5 s); client work ≈ 55 ms for 681 markers |
| Drawer first open → 382 rows (S3) | **3.56 s** | `control-center/controls` server wait 3.56 s (9.7 KB gzip / 175 KB JSON); render 11 ms |
| Scroll the whole list (S4) | **88 s** until every row is hydrated | 44 value-summary POSTs (3–12 rows each, avg 8.6) all dispatched at once; each costs **≈ 3.9 s server** and the host runs 2 at a time |
| Marker → edit panel (S5) | **1.10 s** click-to-ready | hover `descriptor` request 1.22 s, queued behind two hover-triggered prefetch batches; render 9 ms |
| Palette parent → 19-swatch grid (S6) | **13.9 s** | `control-center/open` server wait 13.7–14.1 s; render 1 ms |

Root causes (all confirmed with read-only WP-CLI probes, §4):

1. **Every control-center request re-materialises ACF's full local field store** — 2.68 s, 3 548 `acf/load_field` — because the Vertical provider's `mapRecord()` calls `acf_get_field()` per curated record and ACF has no cross-request cache for PHP-registered fields. This is E-159 half (a) resurfacing on a second path; perf-b caches Bricks' registry, not ACF's store. It is the ≈ 2.7 s inside S3, S4 (per batch) and S6.
2. **`ControlRegistry::collectValidRecords()` is not memoised per request** — `getVisibleRecord()` costs 62 ms warm, and value-summaries calls it once per public id (9 ids ≈ 0.56 s of pure re-sorting).
3. **Palette open attaches 20 descriptors one at a time** — `addDescriptorToSession()` × 20, each doing `loadSession(…, true)` (402 ms: gz+base64+json decode of the 681-descriptor blob) + re-encode + `set_transient` of a 0.5 MB payload ≈ 0.5 s each → ≈ 10 s of the 13.9 s.
4. **Value-summary batching under-fills** (leading 50 ms window, cap 20): 8–9 rows per batch at the recipe's fast scroll, **2 rows per batch** at a slower 3 000 px/s scroll — and every batch pays root cause 1, so the cost is per batch, not per row.

## 2. Method

- Host: LocalWP (nginx + PHP-FPM 8.4.4, effective PHP concurrency **2** — see F8), Bricks 2.3.8, ACF PRO 6.6.2 (from Bricks Advanced Themer), Vertical theme, DBVC branch `codex/visual-editor-r6-site-manager-workspace` at `8201970` + the uncommitted `AcfFieldsCache::contextAllowsSeed()` admin exclusion. Both perf switches on; site restarted before S1.
- Browser: the maintainer's Chrome (Claude in Chrome tab 989924045), logged in as `agentadminuser1`, homepage `https://dbvc-codexchanges.local/?dbvc_ve_perf=1` (681 markers). Viewport 1440×900 for S1, 1280×720 (window 1280×897) for S2–S6, DPR 2. Site Manager drawer closed via its persisted state for boot runs; all BCC groups expanded for S4/S6 (382 of 401 rows visible; the maintainer's own expansion state was restored afterwards).
- Discipline per the recipe: 1 warm-up (discarded) + 5 counted runs; `document.hidden === false` asserted on every counted run; navigate to a static CSS URL between runs (`about:blank` is refused by the tool); wait for `readyState === 'complete'` + `dbvc.ve.overlay_boot`, then 2 s settle; `performance.clearMarks()/clearMeasures()` before each measured action; 500 ms settle after; capture via the `dbvc.ve.*` User Timing measures aggregated per span (count / total / max) + Resource Timing for the REST calls (`responseStart − requestStart` = server wait, `requestStart − startTime` = client queueing). Resource Timing buffer raised to 5 000 from S4 on (the default 250 overflowed in the S4 warm-up).
- Actions: S3 dispatches `dbvc:visual-editor:control-center:toggle` (the toolbar button's own path); S4 scrolls `.dbvc-ve-control-center__table-wrap` by 250 px per animation frame (≈ 15 000 px/s, full 43.7 k px list in 2.9 s ≈ the recipe's "3 seconds to cross the list") and then polls until every value-summary POST completed; S5 dispatches `mouseover` on the hero eyebrow text marker (family text), clicks the Edit badge 300 ms later and waits for `panel.render` + an input in the panel body; S6 opens the `vertical:palette_vertical_global_palette` row (19 leaves) with its Open button after its own value-summary batch finished, waiting for 19 cells + hex labels.
- Discards: S2 one run with the tab hidden (E-123 confirmed — `badges.query_collection_mount` 1 201 ms vs ≈ 50 ms foreground); S4 one warm-up and one run contaminated by a hover-triggered descriptor prefetch (kept because it is itself a finding, F6); S3/S5/S6 warm-ups. Each discard's JSON carries a `notes` field.

## 3. Results

Values are ms; "total" is the per-run sum over `count` spans; median / p10 / p90 over the 5 counted runs; "max single" is the largest single span seen.

### S1 — cold overlay boot, 1440×900

| span | count/run | median | p10 | p90 | max single |
|---|---|---|---|---|---|
| `overlay_boot` | 1 | 1 303 | 1 151 | 1 587 | 1 636 |
| `api.getSession` (= `session.public_map_request`) | 1 | 1 218 | 1 071 | 1 503 | 1 556 |
| `badges.query_collection_mount` | 1 | 47.8 | 46.6 | 54.0 | 56.4 |
| `markers.mount` | 1 | 4.6 | 4.2 | 4.9 | 5.0 |
| `markers.scan` | 5 | 1.0 | 0.8 | 1.4 | 0.5 |
| `markers.recover_query_collections` | 4 | 1.0 | 0.6 | 1.1 | 0.5 |
| `prefetch.setup`, `session.public_map_sync` | 1 each | < 0.5 | | | |

Page navigation (full Bricks render, logged in): TTFB median 14.3 s, `load` 23.1 s — outside the drawer's scope (see F8).

### S2 — cold overlay boot, 1280×720

`overlay_boot` 1 269 (1 174 / 1 473, max 1 604); `api.getSession` 1 186 (1 092 / 1 385); `badges.query_collection_mount` 50.6; `markers.mount` 4.8; everything else < 2 ms. No viewport dependence — the boot is one REST round-trip plus ≈ 55 ms of client work regardless of viewport.

### S3 — drawer open → list fetch → first render (cold)

| span | count/run | median | p10 | p90 | max single |
|---|---|---|---|---|---|
| `drawer.list_fetch` | 1 | **3 560** | 3 510 | 3 590 | 3 596 |
| `drawer.render_list` | 2 | 10.7 | 9.7 | 12.2 | 12.7 |
| `drawer.open` | 1 | 0.9 | 0.6 | 1.4 | 1.6 |

Toggle → 382 rows in DOM: 3 489–3 645 ms. The list request (`GET …/control-center/controls`): server wait 3 560 ms, download 1 ms, 9 677 B transferred / 175 297 B decoded. 12 filter chips. Warm-up (first list after restart) was 3 620 ms — no cold/warm difference, i.e. nothing about this request is cached across requests.

### S4 — drawer scroll → IntersectionObserver hydration cascade

| metric | median [min–max] over 5 runs |
|---|---|
| rows visible / rows queued for summaries | 382 / 379 |
| scroll distance / duration | 46 554 px / 2 913 ms (250 px per frame) |
| `drawer.value_summary.io_trigger` | 173 callbacks, **6.4 ms total** (max 0.4 ms) |
| value-summary POSTs / batch sizes | 44 per run; histogram `{3:1, 7:3, 8:14, 9:17, 10:9}` (one run had an 11, one a 12 and a 1) — never 20 |
| client queueing (`requestStart − startTime`) | 1 ms — all 44 dispatched immediately (HTTP/2, `maxConcurrent` 44) |
| server wait per POST (min / median / max) | **3 887–4 013 / 44 987–46 650 / 84 695–87 085 ms** — the first pair finishes at ≈ 4 s, then two more every ≈ 3.8 s |
| all 44 complete | **87.6–90.0 s** after the scroll started |
| bytes | 26.0 KB transferred / 43.3 KB decoded for all 44 |
| `drawer.value_summary.batch_flush` | 44 spans, sum ≈ 1 970 s — the span wraps the fetch, so it measures the server queue, not client work |

At a slower 3 000 px/s scroll (warm-up, discarded) the same list produced **≈ 190 POSTs of 2 rows** (one of 1). In both cases navigating away after the capture did **not** relieve the server: the dispatched requests kept running and a following `/wp-json/` probe waited **104 s** (then 2.9 s, then 0.7 s).

### S5 — single-color/text panel open (marker hover → Edit badge)

| span | count/run | median | p10 | p90 |
|---|---|---|---|---|
| `descriptor_request` (= `api.getDescriptor`) | 1 | **1 221** | 1 205 | 1 226 |
| `panel.open.marker` | 1 | 1 085 | 1 074 | 1 095 |
| `descriptor_batch_request` (= `api.getDescriptors`, hover prefetch) | 3 | 2 120 total (max 725) | | |
| `prefetch.pump` | 112 | 41 total | | |
| `panel.render` | 1 | 9.2 | 8.6 | 9.5 |

Timeline per run (relative to the hover): two prefetch batches (4 tokens each) at +1 ms and +21 ms, the hovered token's own `descriptor` request at +183 ms, badge click at +323 ms, panel ready **1 090–1 118 ms after the click**. Server waits: batches 685–725 ms (≈ the WP floor), the single descriptor **1 199–1 224 ms** because it queues behind the two batches on the 2 workers; a third batch follows at +720 ms. Payloads 6 KB (single) / 26–32 KB (batches).

### S6 — palette-parent Open → 19-swatch grid

| span | count/run | median | p10 | p90 | max |
|---|---|---|---|---|---|
| `drawer.open_row` | 1 | **13 866** | 13 748 | 14 132 | 14 194 |
| `panel.open.toolbar` | 1 | 42.3 | 41.0 | 48.3 | 51.4 |
| `drawer.render_list` | 2 | 36.6 total | | | |
| `panel.render` | 1 | 1.1 | | | 2.0 |

Click → grid ready 13 779–14 350 ms; exactly one request (`POST …/control-center/open`, server wait 13 677–14 109 ms, 57 982 B decoded — the parent + 19 pre-minted leaf descriptors of R5.later-y-3). The warm-up additionally showed `api.touchSession` 730 ms and one prefetch batch after the open (panel open un-pauses the viewport prefetch).

## 4. Server-side attribution (read-only WP-CLI probes, `--user=6`, same request context as the sweep)

`R5-LATER-PERF-TTFB/cc-endpoint-probe.php` and `cc-getcontrols-cold-probe.php` (`wp eval '1;' --user=<editor> --require=<probe>`; the first also takes `DBVC_PROBE_SESSION=<ves_id>`) — reflection into the booted `DBVC_Visual_Editor_Addon::$runtime`, timers around the registry calls the three controllers make, counting SQL (`query` filter) and `acf/load_field` invocations. CLI numbers include cold compile, so absolute values run ≈ 10 % above PHP-FPM; the shape is what matters.

| call | ms | queries | `acf/load_field` | reading |
|---|---|---|---|---|
| `ControlRegistry::listControls()` first call in the request | **3 826** | 441 | **3 548** | the S3 request body |
| same, second call | 63 | 0 | 0 | |
| Vertical `loadRecords()` (curation JSON) | 3 | 0 | 0 | not the cost |
| Vertical `mapRecord(#0)` — first ACF touch | **2 683** | 41 | 3 548 | `acf_get_field()` for a PHP-registered key makes ACF load every local field group's fields (49 groups, 3 561 loads) — E-159 half (a) |
| `mapRecord()` × remaining 399 | 148 | 398 | 0 | one uncached `get_option('_options_<field>')` per record (`resolveStatus`/description reads the stored value's field-key meta) |
| `maybeExpandRepeaterRecord()` × 400, `countPaletteMembers()` | 3 | 0 | 0 | |
| `getControls()` warm | 51 | 0 | 0 | |
| `getVisibleRecord(<any id>)` warm | **62** | 0 | 0 | `collectValidRecords()` rebuilds + re-sorts 401 records per call — S4 pays it once per id per batch |
| `buildDescriptorForRecord(leaf)` / `(text)` | 0.4 / 0.1 | 0 | 0 | |
| `buildValueSummaryForRecord(leaf)` / `(text)` | 1.1 / 0.4 | 1 | 0 | cheap — the per-row work is not the problem |
| `buildDescriptorForRecord(palette parent, 19 leaves)` | 18 | 23 | 0 | cheap |
| `get_transient(session)` | 17 | 1 | | payload serialises to 494 872 B (`public_map` 408 entries + gz/base64 descriptor blob) |
| `EditableRegistry::loadSession(id, true)` | **402** | 5 | | descriptor blob decode (base64 → gzip → json for ≈ 700 descriptors) |

So, per endpoint: **controls list** = 0.7 s floor + 2.7 s ACF materialisation + 0.15 s option reads ≈ 3.55 s (measured 3.56). **value-summaries** = 0.7 + 2.7 + 0.062 × N + ≈ 1 ms × N ≈ 3.4 + 0.06 N s (measured 3.9–4.0 s for 8–10 ids). **open (palette parent)** = 0.7 + 2.7 + 20 × (`loadSession` 0.4 + gz re-encode + `set_transient` 0.5 MB ≈ 0.5) ≈ 13.4–14 s (measured 13.7–14.1). Nothing in the three controllers is inherently slow; they pay a schema materialisation they do not need and a session round-trip per descriptor.

Disclosure: the probe's `loadSession()` call runs the registry's own TTL refresh, which very likely bumped `refreshed_at` on the browser session `ves_j9ntjhi1a457` (one transient write, no content change — the same write every editor request makes). No other writes.

## 5. Findings and recommendations

Ranked by editor-visible impact ÷ effort. Effort: S ≤ ½ day, M ≈ 1–2 days incl. tests.

| # | Finding | Evidence | Fix | Gain | Effort |
|---|---|---|---|---|---|
| **F1** | Control-center requests re-materialise ACF's local field store (2.7 s) on every list / value-summary / open call | §4 rows 1, 4; S3 3.56 s; S4 ≈ 3.9 s per batch; S6 | **R5.later-perf-c:** cache the Vertical provider's *mapped* control records (401 × small arrays) in a fingerprinted transient — same posture as perf-b (fingerprint = curation JSON mtime + ACF groups/versions + `AcfFieldsCache` fingerprint, event-salted by the same triggers, verified daily). `getControls()` then never touches ACF on list/summary requests; `open`/save keep their live `acf_get_field()` calls. Alternative with the same effect but broader reach: warm ACF's own store from a cache (the deferred "perf-c (ACF-store warm-up)") — harder, ACF exposes no clean seam. | −2.7 s on S3, on every S4 batch and on S6 | M (DBVC + Vertical) |
| **F2** ✅ perf-c | `collectValidRecords()` not memoised per request | §4 `getVisibleRecord` 62 ms; value-summaries calls it per id | Memoise the records array for the request (invalidate on `registerProvider`); `getVisibleRecord` becomes a map lookup | −0.5 s per 9-row batch (−22 s across an S4 sweep) | S |
| **F3** ✅ perf-c | Palette open attaches the parent + 19 leaves with 20 separate `addDescriptorToSession()` round-trips (load + decode + encode + 0.5 MB `set_transient` each) | §4 `loadSession` 402 ms; S6 13.9 s vs 18 ms to build the descriptor | `addDescriptorsToSession(array)` — one load, one map rebuild, one write; have `ControlCenterOpenController` collect first, attach once. Same for any future multi-descriptor open. | −≈ 9.5 s on S6 (with F1: 13.9 s → ≈ 1.2 s) | S |
| **F4** | Value-summary batches under-fill and are all dispatched at once; each costs a full request | S4: 44 POSTs (avg 8.6 rows, never 20) at fast scroll, ≈ 190 × 2 rows at 3 000 px/s; `maxConcurrent` 44; 88 s to hydrate; a 104 s backlog persisted after navigating away | (a) trailing/adaptive window: keep the timer alive while rows keep arriving (cap ≈ 150 ms) and flush at 20; (b) cap in-flight batches at 2 and keep a queue — later batches then carry 20 rows; (c) `AbortController` on drawer close / `pagehide`; (d) after F1 each POST drops to ≈ 0.8 s | S4: 44 → ≈ 20 POSTs; with F1 + F2 ≈ 20 × 0.9 s / 2 ≈ 9 s to hydrate the whole list (was 88 s); no host saturation | S–M |
| **F5** | `loadSession(…, true)` decodes the whole descriptor blob (402 ms) whenever any descriptor is attached or read; the payload is 0.5 MB for 681 markers | §4 | Store descriptors per token (or per chunk) so attach/read touch one entry; keep `public_map` as the only whole-session structure. Also makes `set_transient` writes small. | −0.4 s per attach/read; halves the S6 residual after F3 | M |
| **F6** | Hover-triggered viewport prefetch delays the hovered token's own descriptor: two 4-token batches are sent 1–21 ms after the hover, the single descriptor 183 ms later queues behind them (1.22 s vs ≈ 0.7 s floor). While requests are queued `prefetch.pump` re-arms every idle period (2 034 pumps / 572 ms CPU in the contaminated S4 run) | S5 timeline; `s4/discarded-hover-prefetch.json` | Send the hovered token first and hold prefetch until it resolves (or give it its own concurrency slot); back off `scheduleViewportPrefetch()` while `viewportPrefetchInFlight ≥ concurrency` (re-arm from the request's `finally`, not from idle) | S5 click-to-panel ≈ 1.1 s → ≈ 0.75 s; no busy-wait | S |
| **F7** | Boot `getSession` = floor + ≈ 0.5 s; client boot work ≈ 55 ms | S1/S2 | Not worth a slice on its own; re-measure after F1 (the 0.5 s is session create/persist of the 0.5 MB payload — F5 shrinks it) | ≈ −0.3 s | — |
| **F8** | Host effects to read the absolutes against: PHP-FPM effectively runs 2 requests at a time here (completion pairs every ≈ 3.8 s in S4), so queue waits scale with request count; production hosts change the queueing, **not** the ≈ 2.7 s + per-request costs. Page TTFB is 14 s (full Bricks render for a logged-in editor) — the `?dbvc_ve_perf=1` page itself, not the drawer; unchanged by perf-a/b because the render, not `init`, dominates it | S1–S6 `navigation.ttfb`; S4 completion pattern; §4 | Out of scope for the drawer; worth its own look (Bricks render for logged-in users) | — | — |
| **F9** | Measurement hygiene confirmed: a hidden tab (background tab, macOS fullscreen space, full occlusion) throttles timers ≈ 25× (E-123); Resource Timing's default 250-entry buffer overflows during S4 | `s2/discarded-hidden-tab.json`; S4 warm-up | Recipe amendments below | — | — |

**Answers to the recipe's questions.** S1/S2: boot is one REST round-trip (1.2 s) + 55 ms client, viewport-independent. S3: 3.5 s, all server. S4: IO overhead is ≈ 6 ms for 173 callbacks — dwarfed by the batch fetches by four orders of magnitude; batches are < 20 (F4). S5: 1.1 s, all server, with the hover prefetch in the way (F6). S6: 13.9 s, all server (F1 + F3).

**R5.later-cache premise.** A client-side cache would hide S3's 3.5 s on repeat opens within a session lifetime — but the same 2.7 s is paid on *every* value-summary batch and on open/save, which a client cache cannot cover. Fix the server (F1–F3) first; then re-decide R5.later-cache against a ≈ 0.8 s list request, where it is probably unnecessary.

**Recipe amendments (for the next sweep).** Add "assert `document.hidden === false` in the capture (discard if true)"; "raise `performance.setResourceTimingBufferSize(5000)` before S4"; "S4: poll until every value-summary POST completes and record server wait / concurrency from Resource Timing"; "S5: hover → Edit badge (`.dbvc-ve-badge`) — clicking the marker itself does not open the panel"; "between S4 runs, wait for the server to drain (`/wp-json/` TTFB < 1.5 s)". Keep the static-CSS "away" page.

## 6. Proposed sequencing

1. **R5.later-perf-c (server):** F2 + F3 (both S, both pure DBVC, no behaviour change) → then F1 as a fingerprinted control-record cache (DBVC `ControlRegistry` cache seam + Vertical provider participation; cross-repo like perf-b's WS Form bridge). Verify with S3/S4/S6 re-runs: targets S3 ≈ 0.9 s, S6 ≈ 1.2 s, S4 per-batch ≈ 0.9 s.
2. **R5.later-perf-d (client):** F4 + F6 in `brand-control-center-app.js` / `overlay-app.js` — extend the jsdom suites (batch window, in-flight cap, abort on close; prefetch hold + back-off).
3. F5 only if the residual `loadSession` cost still shows after 1–2.
4. Then S7/S8 (below) and the R5.later-cache decision.

## 7. S7 / S8 — maintainer instructions (these save swatches)

Both scenarios write a palette colour, so they are yours. Same window/tab setup as the sweep (window 1280×897 → viewport 1280×720, tab in front, not fullscreen, DevTools closed or docked without covering the page).

1. Load `https://dbvc-codexchanges.local/?dbvc_ve_perf=1`, wait for the page, open Global Brand Controls, expand **Site Settings Advanced**, scroll to **Vertical Global Palette** and press **Open**; wait for the 19 swatches (≈ 14 s today).
2. In the Console, before each measured edit: `performance.clearMarks(); performance.clearMeasures(); window.__c0 = performance.now();`
3. **S7 (first edit of a fresh swatch):** click a swatch that has not been edited this session, pick a colour one step away from the current one, wait for the cell's status to read **Saved**, wait 500 ms, then run the capture below. Hard-refresh, reopen the palette, use the *next* swatch for the next run (5 runs).
4. **S8 (second edit of an already-edited swatch):** same page state as after an S7 run (do **not** refresh), clear timing, edit the *same* swatch again, wait for **Saved** + 500 ms, capture (5 runs, reusing swatches edited in S7).
5. Capture snippet (paste in Console, copy the printed JSON into `R5-LATER-PERF-MEASUREMENTS/s7/runN.json` / `s8/runN.json`; the `notes` field is yours):

```js
(() => { const all = performance.getEntriesByType('measure').filter(e => e.name.startsWith('dbvc.ve.')).sort((a,b) => a.startTime - b.startTime);
const agg = {}; for (const e of all) { const a = agg[e.name] || (agg[e.name] = {count:0,totalMs:0,maxMs:0}); a.count++; a.totalMs += e.duration; a.maxMs = Math.max(a.maxMs, e.duration); }
const req = performance.getEntriesByType('resource').filter(r => r.startTime >= window.__c0 && /wp-json/.test(r.name)).map(r => ({ url: r.name.replace(/^.*\/visual-editor\//,'').replace(/session\/[^/]+\//,'s/'), startMs: Math.round(r.startTime - window.__c0), serverWaitMs: Math.round(r.responseStart - r.requestStart), bytes: r.decodedBodySize }));
const out = { scenario: 'S7', run: 'runN', capturedAt: new Date().toISOString(), viewport: {w: innerWidth, h: innerHeight, dpr: devicePixelRatio}, hidden: document.hidden, aggregate: agg, requests: req, notes: '' };
console.log(JSON.stringify(out, null, 2)); return out; })();
```

Expected per the recipe: S7 shows `save.request` plus **two** REST calls (`/control-center/open` is a plain `fetch`, not a `dbvc.ve.*` span — it appears in `requests`); with R5.later-y-3's pre-minted tokens the open call should already be absent, which is the thing to confirm. S8 should show `save.request` and one call. Given F1, expect every save to sit on the same ≈ 3.4 s floor until perf-c lands — note it, it is the same finding.

## 8. Restoration and hygiene

The sweep changed only per-viewer browser state and restored it: Site Manager drawer persisted state (`dbvc-ve-workspace:v1` back to `isOpen:true`, Content › Pages, Oldest first), BCC expanded-groups list back to the maintainer's four groups, temporary `dbvc-perf-*` keys removed. No site content, options or settings were touched by the agent; the only server-side write is the session TTL refresh disclosed in §4. Probes are checked in under `R5-LATER-PERF-TTFB/` (read-only, reflection-based) and the per-scenario statistics in §3 come from `R5-LATER-PERF-MEASUREMENTS/analyze.py` (run from the plugin root) so a perf-c re-sweep can be compared like for like.
